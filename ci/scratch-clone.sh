#!/usr/bin/env bash
#
# A private working tree for one run.
#
# The checkout in an agent workspace is *not* private to the run using it. Two
# runs of the same agent share one directory, and they overlap: one heartbeat can
# still be finishing while the next has started. So `git checkout`, `git am`,
# `git rebase` and `git reset --hard` in that directory are writes to somebody
# else's working tree.
#
# This happened twice on 2026-08-20, both times inside one agent's own workspace:
#
#   * QA's tree was checked out to another branch, `git am`-ed, aborted and hard
#     reset onto a fetched head, three minutes after a commit. Nothing was lost
#     only because the work was already pushed.
#   * Nine `ci-verify-two97/*` branches were committed and pushed out of this
#     workspace after the run that owned it had ended, by a second run.
#
# `git reset --hard` and `git checkout` leave a reflog entry for *committed* work.
# They leave nothing at all for uncommitted work. There is no undo and no trace,
# so this cannot be a rule people remember — it has to be a different directory.
#
# This is TWO-103 one level up: same cause, a fixed name nobody owns. TWO-103 is
# two acceptance runs colliding on branch names; this is two runs colliding on a
# working tree.
#
# So: clone the workspace checkout into the run's own scratch directory and work
# there. `--shared` means no copy of the object store and no network — the clone
# is a few hundred kilobytes and takes a moment — and Paperclip deletes the whole
# directory when the run ends, so there is nothing to clean up and nothing to
# leak into the next run.
#
# Usage:
#   ./ci/scratch-clone.sh                      # clone this repo into the run scratch dir
#   ./ci/scratch-clone.sh --branch main        # check that ref out instead of source HEAD
#   ./ci/scratch-clone.sh --source DIR --dest DIR
#   ./ci/scratch-clone.sh --no-fetch           # skip the `git fetch origin` (offline)
#
#   cd "$(./ci/scratch-clone.sh)"              # the path is the only thing on stdout
#
# What you get, which a plain `git clone --shared` does not give you:
#
#   origin       the *source's* origin — GitHub — not the filesystem path we cloned
#                from. `gh`, `git ls-remote origin` and `verify-pipeline.sh --run`
#                all read remote.origin.url and all need it to be the real remote.
#   workspace    the path we cloned from, kept as a remote so its branches can be
#                fetched again without the network.
#   an identity  user.name/user.email are set per-repository in these workspaces,
#                not globally, so a fresh clone cannot commit until they are copied.
#   a marker     `paperclip.runScratch` records the run id that owns this clone.
#                `verify-pipeline.sh --run` refuses to start unless it finds its
#                own id there — that is what makes this enforced rather than
#                advisory.
#
# One caveat with `--shared`: the clone borrows the source's object store through
# `.git/objects/info/alternates` rather than copying it. If the source repository
# prunes (`git gc --prune=now`) while this clone is alive, objects the clone
# reached through the alternate can go away underneath it. Commits made in the
# clone and pushed to origin are safe; a clone left lying around across runs is
# not. It is scoped to one run for that reason too.

set -euo pipefail

SOURCE=""
DEST=""
BRANCH=""
FETCH=1

while [ "$#" -gt 0 ]; do
  case "$1" in
    --source) SOURCE="${2:?--source needs a directory}"; shift 2 ;;
    --dest)   DEST="${2:?--dest needs a directory}";     shift 2 ;;
    --branch) BRANCH="${2:?--branch needs a ref}";       shift 2 ;;
    --no-fetch) FETCH=0; shift ;;
    -h|--help) sed -n '2,60p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2; exit 0 ;;
    *) printf 'scratch-clone: unknown argument: %s\n' "$1" >&2; exit 2 ;;
  esac
done

say()  { printf '\033[2mscratch-clone:\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[31mscratch-clone: %s\033[0m\n' "$*" >&2; exit 1; }

# Default source: the repository this script is committed in, whatever the caller's
# working directory is.
[ -n "$SOURCE" ] || SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE="$(git -C "$SOURCE" rev-parse --show-toplevel 2>/dev/null)" \
  || fail "not a git repository: ${SOURCE}"

if [ -z "$DEST" ]; then
  # Paperclip owns this directory and removes it when the run ends. Without it we
  # have nowhere run-private to put the clone, and guessing — /tmp/two-web, a
  # sibling of the workspace — reintroduces the exact bug: a fixed name that
  # outlives the run and that the next run will happily reuse.
  [ -n "${PAPERCLIP_RUN_SCRATCH_DIR:-}" ] \
    || fail "PAPERCLIP_RUN_SCRATCH_DIR is not set and no --dest was given. Outside a Paperclip run, pass --dest explicitly and delete it yourself."
  DEST="${PAPERCLIP_RUN_SCRATCH_DIR%/}/$(basename "$SOURCE")"
fi

RUN_ID="${PAPERCLIP_RUN_ID:-}"

# Idempotent within a run: a second call hands back the clone the first one made
# rather than refusing or, worse, wiping it. Anything else living at that path is
# not ours to delete.
#
# This is checked before the "dest is inside source" refusal below, and that order
# matters for the commonest case of all: running the script a second time from
# inside the clone the first call made. Source and destination are then the same
# directory, and the useful answer is "you are already there", not a complaint
# about nesting.
if [ -e "$DEST" ]; then
  existing="$(git -C "$DEST" config --get paperclip.runScratch 2>/dev/null || true)"
  if [ -n "$RUN_ID" ] && [ "$existing" = "$RUN_ID" ]; then
    say "reusing this run's clone at ${DEST}"
    echo "$DEST"
    exit 0
  fi
  fail "${DEST} already exists and is not this run's scratch clone (paperclip.runScratch=${existing:-unset}). Remove it, or pass --dest."
fi

case "$DEST" in
  "$SOURCE"|"$SOURCE"/*) fail "--dest is inside the source repository: ${DEST}" ;;
esac

# No network and no copy of the object store: the clone borrows the source's
# objects through .git/objects/info/alternates.
if [ -n "$BRANCH" ]; then
  git clone --shared --quiet --branch "$BRANCH" "$SOURCE" "$DEST" \
    || fail "could not clone ${SOURCE} at ${BRANCH}"
else
  git clone --shared --quiet "$SOURCE" "$DEST" \
    || fail "could not clone ${SOURCE}"
fi

cd "$DEST"

# `clone` points origin at the path it cloned from and files the source's *local*
# branches under refs/remotes/origin/*. Both are wrong for anything that then
# talks to GitHub: `verify-pipeline.sh --run` reads remote.origin.url to name the
# repository it asks the Actions API about, and refuses a filesystem origin
# outright. Worse, leaving those refs under `origin/` would mean `origin/main`
# names the workspace checkout's local main — a branch that can be days behind
# the real one — while looking like the remote-tracking ref everyone trusts.
#
# So the path we cloned from becomes `workspace`, taking its refs with it, and
# `origin` is the source's origin with no refs until something fetches them.
git remote rename origin workspace
upstream="$(git -C "$SOURCE" config --get remote.origin.url 2>/dev/null || true)"
if [ -n "$upstream" ]; then
  git remote add origin "$upstream"
else
  say "the source has no 'origin' remote; this clone has only 'workspace'."
fi

# These are set per-repository here, not in ~/.gitconfig, so a fresh clone has no
# identity and the first `git commit` in it fails — after the patch has been
# applied, which is the least useful moment to find out.
for key in user.name user.email commit.gpgsign; do
  value="$(git -C "$SOURCE" config --get "$key" 2>/dev/null || true)"
  if [ -n "$value" ]; then git config "$key" "$value"; fi
done
git config --get user.email >/dev/null 2>&1 \
  || say "no user.email is configured anywhere; commits from this clone will fail."

git config paperclip.scratchSource "$SOURCE"
if [ -n "$RUN_ID" ]; then git config paperclip.runScratch "$RUN_ID"; fi

if [ "$FETCH" = 1 ] && [ -n "$upstream" ]; then
  # Not fatal. The clone is fully usable offline against `workspace/*`; only the
  # remote-tracking refs are missing, and everything that needs them says so.
  git fetch origin --quiet 2>/dev/null || say "could not fetch origin (offline?) — refs/remotes/origin is empty."
fi

say "cloned ${SOURCE}"
say "  -> ${DEST} on $(git rev-parse --abbrev-ref HEAD), origin ${upstream:-unset}"
[ -n "$RUN_ID" ] || say "  PAPERCLIP_RUN_ID is unset, so this clone is not marked as owned by a run."

echo "$DEST"
