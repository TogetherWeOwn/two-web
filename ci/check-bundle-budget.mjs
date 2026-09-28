// Vite bundle size budget (TOG-5629).
//
// Reads ci/bundle-budget.json, builds nothing, and compares each budgeted
// entrypoint's emitted asset in public/build/manifest.json against its raw and
// gzip ceilings. Exit 0 when everything fits, 1 when anything breaches, 2 when
// the check itself cannot run (missing manifest, unreadable budget) — because a
// budget that cannot be read is not being enforced, and it should look like one.
//
// Why raw AND gzip: raw catches dependency accidents (an axios-grade import is
// 48 KB minified, TOG-53); gzip catches what the budget profile actually pays
// for on Slow 4G (~184 KB/s, see AssetCompressionTest). A file that is large
// both ways fails twice in one line — one breach per entrypoint, not per
// metric, so the report reads as "this asset grew" rather than two numbers.
//
// Why manifest, not the assets directory: the manifest is what @vite resolves
// at runtime, so it is the asset the page really loads. Extra files in the
// directory (source maps, ci-verify fixtures) are not what any page requests
// and do not count; an entrypoint with no manifest entry is a broken build and
// fails here rather than passing vacuously.
//
// Why no threshold on total: per-entrypoint ceilings already sum to a total,
// and a total alone lets one asset eat another's headroom. The failure mode
// this exists to prevent is a 48 KB import landing in the 1-byte app.js while
// the total still fits — per-entry ceilings catch exactly that.
//
// Usage: node ci/check-bundle-budget.mjs [--selftest]
//
// `--selftest` drives the checker against synthetic manifests and budgets in a
// temp dir: a clean tree passes, an over-budget JS or CSS entry fails naming
// the entry, and a missing manifest or budget exits 2 rather than passing.
// Offline, milliseconds, no build — it runs in `static` so a checker that
// quietly stops checking goes red there instead of looking green everywhere.

import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { gzipSync } from 'node:zlib';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '..');

function fail(message) {
  console.error(`::error::bundle budget: ${message}`);
}

function loadJson(path) {
  return JSON.parse(readFileSync(path, 'utf8'));
}

/**
 * Check one tree. Returns { ok, errors } — never throws for a breached budget,
 * only for a tree that cannot be read at all.
 */
export function checkTree({ root, budget }) {
  const errors = [];
  const manifestPath = join(root, 'public/build/manifest.json');
  let manifest;
  try {
    manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  } catch (err) {
    return { ok: false, unreadable: true, errors: [`cannot read ${manifestPath}: ${err.message}`] };
  }

  for (const [entry, ceiling] of Object.entries(budget.budgets ?? {})) {
    const emitted = manifest[entry]?.file;
    if (!emitted) {
      errors.push(`${entry}: no \`file\` in public/build/manifest.json — the build did not emit this entrypoint`);
      continue;
    }
    const assetPath = join(root, 'public/build', emitted);
    let bytes;
    try {
      bytes = readFileSync(assetPath);
    } catch (err) {
      errors.push(`${entry}: emitted ${emitted} is not on disk: ${err.message}`);
      continue;
    }
    const raw = bytes.length;
    // Default level, matching what production serves: the budgets job fronts
    // `artisan serve` with ci/compressing-proxy.mjs, whose createGzip() runs at
    // the zlib default — the same default gzipSync uses. Pinning a level here
    // would measure a different file than the one Lighthouse downloads.
    const gzipped = gzipSync(bytes).length;
    const breaches = [];
    if (raw > ceiling.maxRawBytes) {
      breaches.push(`raw ${raw}B over ${ceiling.maxRawBytes}B`);
    }
    if (gzipped > ceiling.maxGzipBytes) {
      breaches.push(`gzip ${gzipped}B over ${ceiling.maxGzipBytes}B`);
    }
    if (breaches.length > 0) {
      errors.push(`${entry} (${emitted}): ${breaches.join(', ')}`);
    } else {
      console.log(`  ok  ${entry} -> ${emitted}: raw ${raw}B / gzip ${gzipped}B`);
    }
  }

  return { ok: errors.length === 0, unreadable: false, errors };
}

function run(root) {
  const budgetPath = join(root, 'ci/bundle-budget.json');
  let budget;
  try {
    budget = loadJson(budgetPath);
  } catch (err) {
    fail(`cannot read ${budgetPath}: ${err.message} — no thresholds, no enforcement`);
    return 2;
  }

  let result;
  try {
    result = checkTree({ root, budget });
  } catch (err) {
    fail(`crashed while reading the tree: ${err.message}`);
    return 2;
  }

  if (result.unreadable) {
    for (const e of result.errors) fail(e);
    return 2;
  }
  if (!result.ok) {
    for (const e of result.errors) fail(`${e}. If the growth is deliberate, raise the ceiling in ci/bundle-budget.json in its own commit that says what grew and why.`);
    return 1;
  }
  console.log('bundle budget: every entrypoint fits.');
  return 0;
}

function selftest() {
  let failures = 0;
  const pass = (name) => console.log(`PASS  ${name}`);
  const failCase = (name, detail) => {
    console.error(`FAIL  ${name}: ${detail}`);
    failures += 1;
  };

  // A pristine tree: one tiny JS entry, one small CSS entry, both under budget.
  const fixture = () => {
    const dir = mkdtempSync(join(tmpdir(), 'bundle-budget-'));
    mkdirSync(join(dir, 'public/build/assets'), { recursive: true });
    mkdirSync(join(dir, 'ci'), { recursive: true });
    const js = Buffer.from('console.log("hi");\n');
    const css = Buffer.from('.a{color:red}\n');
    writeFileSync(join(dir, 'public/build/assets/app.js'), js);
    writeFileSync(join(dir, 'public/build/assets/app.css'), css);
    writeFileSync(
      join(dir, 'public/build/manifest.json'),
      JSON.stringify({
        'resources/js/app.js': { file: 'assets/app.js' },
        'resources/css/app.css': { file: 'assets/app.css' },
      }),
    );
    const budget = {
      budgets: {
        'resources/js/app.js': { maxRawBytes: 5120, maxGzipBytes: 2048 },
        'resources/css/app.css': { maxRawBytes: 76800, maxGzipBytes: 15360 },
      },
    };
    writeFileSync(join(dir, 'ci/bundle-budget.json'), JSON.stringify(budget));
    return { dir, budget };
  };

  // clean: the real shape of a passing tree.
  {
    const { dir, budget } = fixture();
    try {
      const r = checkTree({ root: dir, budget });
      if (r.ok) pass('clean tree fits');
      else failCase('clean tree fits', r.errors.join('; '));
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }

  // over-budget JS: the axios accident — a 48 KB import in the 1-byte app.js.
  {
    const { dir, budget } = fixture();
    try {
      writeFileSync(join(dir, 'public/build/assets/app.js'), Buffer.alloc(50_000, 'x'));
      const r = checkTree({ root: dir, budget });
      if (!r.ok && r.errors.some((e) => e.includes('resources/js/app.js'))) pass('over-budget JS fails naming the entry');
      else failCase('over-budget JS fails naming the entry', r.ok ? 'passed' : r.errors.join('; '));
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }

  // over-budget CSS: the unconditional-hallmark accident on a public page.
  {
    const { dir, budget } = fixture();
    try {
      writeFileSync(join(dir, 'public/build/assets/app.css'), Buffer.alloc(90_000, 'x'));
      const r = checkTree({ root: dir, budget });
      if (!r.ok && r.errors.some((e) => e.includes('resources/css/app.css'))) pass('over-budget CSS fails naming the entry');
      else failCase('over-budget CSS fails naming the entry', r.ok ? 'passed' : r.errors.join('; '));
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }

  // missing manifest: a build that never ran must not read as "fits".
  {
    const { dir, budget } = fixture();
    try {
      rmSync(join(dir, 'public/build/manifest.json'));
      const r = checkTree({ root: dir, budget });
      if (!r.ok && r.unreadable) pass('missing manifest is unreadable, not green');
      else failCase('missing manifest is unreadable, not green', r.ok ? 'passed' : r.errors.join('; '));
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }

  // entrypoint with no manifest entry: fails rather than passing vacuously.
  {
    const { dir, budget } = fixture();
    try {
      writeFileSync(
        join(dir, 'public/build/manifest.json'),
        JSON.stringify({ 'resources/js/app.js': { file: 'assets/app.js' } }),
      );
      const r = checkTree({ root: dir, budget });
      if (!r.ok && r.errors.some((e) => e.includes('resources/css/app.css'))) pass('unemitted entrypoint fails');
      else failCase('unemitted entrypoint fails', r.ok ? 'passed' : r.errors.join('; '));
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }

  // missing budget file: exit 2 through run(), the "cannot enforce" path.
  {
    const { dir } = fixture();
    try {
      rmSync(join(dir, 'ci/bundle-budget.json'));
      // Silence the ::error:: output inside the selftest; the exit code is the assertion.
      const err = console.error;
      console.error = () => {};
      let code;
      try {
        code = run(dir);
      } finally {
        console.error = err;
      }
      if (code === 2) pass('missing budget exits 2');
      else failCase('missing budget exits 2', `exited ${code}`);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }

  if (failures > 0) {
    console.error(`${failures} selftest case(s) failed — the checker does not check what it claims.`);
    return 1;
  }
  console.log('bundle budget selftest: all cases pass.');
  return 0;
}

const arg = process.argv[2];
if (arg === '--selftest') {
  process.exit(selftest());
} else {
  process.exit(run(repoRoot));
}
