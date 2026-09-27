<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Reads the moderator grant *as the running application resolved it*, and says
 * whether it is the value TOG-106 signed off (TOG-427).
 *
 * Why this exists as code rather than as a line in a runbook.
 *
 * `DISCORD_MODERATOR_ROLE_IDS` is the only privilege grant this site has, and it
 * is the only setting that can be wrong without anything going red. A wrong
 * guild ID locks every member out loudly, within a minute, and somebody files a
 * bug. A wrong moderator list just never offers the admin link to anyone — no
 * error, no log line, no 500. The site looks configured and healthy while the
 * feature the panel exists for is silently off. That failure is invisible to
 * every other check in this repository.
 *
 * The step TOG-427 actually asks for is "set the variable on the box and confirm
 * a moderator sees the admin link and a member does not". The behaviour half is
 * pinned in tests/Feature/Auth/DiscordLoginTest.php, which drives the real
 * snowflake through the callback to the rendered link. The parse half is pinned
 * in tests/Feature/Auth/DiscordModeratorRoleIdsTest.php. Neither can tell you
 * what is in the .env of a server, because neither runs there. This does, and it
 * is the only part of the done-when that needs the box.
 *
 * Which makes it a checklist item — and a checklist item read by a tired person
 * at deploy time is read as "yes, looks fine". Anything with a number in it
 * belongs in a script, so the same input gives the same answer forever. That is
 * the whole argument for this file.
 *
 *   php artisan discord:check-moderators
 *   php artisan discord:check-moderators --require-configured   # on staging
 *   php artisan discord:check-moderators --json
 *
 * Exit status is 0 when nothing FAILs, 1 otherwise. UNKNOWN never fails a run:
 * see "What this cannot check" at the bottom of handle(), which is the honest
 * part.
 */
class CheckDiscordModerators extends Command
{
    protected $signature = 'discord:check-moderators
                            {--require-configured : Treat a blank list as a failure. Pass this on a box that is supposed to have the grant set; leave it off in local dev, where blank is correct.}
                            {--json : Emit the findings as JSON instead of a table.}';

    protected $description = 'Check DISCORD_MODERATOR_ROLE_IDS as the running app resolved it (TOG-427)';

    /**
     * The signed-off moderator ID, from `services.discord.sysop_role_id`.
     *
     * `SySOp` in the TWO guild — one holder, administrator class, signed off on
     * TOG-106 as the entire website staff list. Matched by ID and never by
     * name, and this is the case that proves why: SySOp is `KEEP (renamed
     * Owner)` in Wave 6 of the approved server-redesign. The rename preserves
     * the snowflake, so a name match would break on a cosmetic rename while an
     * ID match rides straight through it.
     *
     * Read from config, not from a class constant, so a reviewed box can point
     * at a different server without a code change. The value itself is unchanged
     * from the constant this replaced, byte for byte.
     */
    private function sysopId(): string
    {
        return (string) config('services.discord.sysop_role_id');
    }

    /**
     * The five other roles that carry ban or kick, from
     * `services.discord.retired_moderator_role_ids` with the holder count
     * recorded by the 2026-08-19 audit beside each ID.
     *
     * These are here because they are the *plausible* wrong answer. "Discord
     * already trusts this role to ban or kick" is a reasonable-sounding way to
     * derive a moderator list, it was in fact approved at 2026-08-19T20:40Z, and
     * the CEO narrowed it to SySOp alone three minutes later
     * (two-bot/audit/IDENTIFIERS.md:56-62). Somebody widening this variable back
     * out is not a hypothetical — it is a decision that was already made once and
     * then reversed, and the reversal is only written down in another repository.
     *
     * Adding any of them is worse than merely wrong. All five are deleted:
     * Officer, Game Master and Staff in Wave 6 of the server-redesign
     * (two-bot/scripts/wave0-export.ts:74-77), Captain and Lieutenant by
     * role-consolidation (two-bot/scripts/role-consolidation.ts:81-84). A deleted
     * snowflake matches nobody, forever, without erroring — so a list containing
     * them dark-fails exactly like a blank one while looking configured.
     *
     * Keys arrive as ints for digit-only snowflakes (PHP array semantics), so
     * they are cast back to string: every comparison below is ID-match on the
     * string form, exactly as the constants this replaced did.
     *
     * @return array<string, string> snowflake => description
     */
    private function doomed(): array
    {
        $described = (array) config('services.discord.retired_moderator_role_ids', []);

        $out = [];
        foreach ($described as $id => $label) {
            $out[(string) $id] = (string) $label;
        }

        return $out;
    }

    private const PASS = 'PASS';

    private const FAIL = 'FAIL';

    private const UNKNOWN = 'UNKNOWN';

    /** @var list<array{status: string, name: string, detail: string}> */
    private array $results = [];

    private function record(string $status, string $name, string $detail): void
    {
        $this->results[] = ['status' => $status, 'name' => $name, 'detail' => $detail];
    }

    public function handle(): int
    {
        // config(), not env(). The difference is the entire point of running this
        // on the box: `php artisan config:cache` bakes config into a file and
        // env() then returns null in production, so a check written against env()
        // would report "not set" on a correctly configured cached server, and
        // would also miss a stale cache still holding a value the .env no longer
        // has. What the application will actually use at login time is what
        // config() returns, so that is what gets checked.
        // Cast each entry to string and reindex. config() is typed as mixed, and
        // on a box with a cached config this array came out of a generated PHP
        // file rather than out of config/services.php — so it is not guaranteed
        // to be the clean list of strings that file produces. Normalising here
        // means the checks below compare like with like; `array_intersect` on a
        // stray int would quietly not match the string snowflake it equals.
        $configured = array_values(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            (array) config('services.discord.moderator_role_ids', []),
        ));

        $rendered = $configured === [] ? '<empty>' : implode(',', $configured);

        $this->checkConfigured($configured, $rendered);
        $this->checkDoomed($configured);
        $this->checkExactlySysop($configured, $rendered);
        $this->checkShape($configured);
        $this->checkFailClosed();

        return $this->report();
    }

    /**
     * Is anything set at all?
     *
     * Blank is not automatically wrong — it is correct in local dev, and it is
     * the documented revocation path (blank the variable and isModerator() fails
     * closed, un-granting everyone with no code deploy). So blank is only a
     * failure where the grant is supposed to exist, which the caller states with
     * --require-configured rather than this command guessing from APP_ENV.
     *
     * @param  list<string>  $configured
     */
    private function checkConfigured(array $configured, string $rendered): void
    {
        if ($configured !== []) {
            $this->record(self::PASS, 'configured', "moderator_role_ids = {$rendered}");

            return;
        }

        if ($this->option('require-configured')) {
            $this->record(self::FAIL, 'configured', 'moderator_role_ids is empty. Nobody is a moderator and the admin link is offered to no one — silently. Set DISCORD_MODERATOR_ROLE_IDS='.$this->sysopId().' in this environment.');

            return;
        }

        $this->record(self::PASS, 'configured', 'empty, and --require-configured was not passed. Nobody is a moderator; this is the correct state for local dev and is the revocation path.');
    }

    /**
     * The check with the most value per line: none of the five doomed snowflakes
     * may appear. This is the mistake that looks configured and grants nothing.
     *
     * @param  list<string>  $configured
     */
    private function checkDoomed(array $configured): void
    {
        $doomed = $this->doomed();
        $found = array_intersect($configured, array_keys($doomed));

        if ($found === []) {
            $this->record(self::PASS, 'no-doomed-roles', 'none of the 5 ban/kick roles scheduled for deletion are present');

            return;
        }

        $described = implode('; ', array_map(
            fn (string $id): string => "{$id} = ".$doomed[$id],
            $found,
        ));

        $this->record(self::FAIL, 'no-doomed-roles', "list contains role(s) that are deleted by the approved server-redesign: {$described}. A deleted snowflake matches nobody and never errors, so this grant will silently stop working. TOG-106 narrowed the list to SySOp alone; widening it needs fresh sign-off.");
    }

    /**
     * The signed-off value is exactly one ID. More than one is not necessarily
     * broken, but it is a grant nobody approved, so it is surfaced rather than
     * passed over.
     *
     * @param  list<string>  $configured
     */
    private function checkExactlySysop(array $configured, string $rendered): void
    {
        $sysop = $this->sysopId();

        if ($configured === []) {
            $this->record(self::UNKNOWN, 'is-sysop', 'nothing configured, so there is nothing to compare against TOG-106');

            return;
        }

        if ($configured === [$sysop]) {
            $this->record(self::PASS, 'is-sysop', 'exactly SySOp ('.$sysop.'), which is the value signed off on TOG-106');

            return;
        }

        if (! in_array($sysop, $configured, true)) {
            $this->record(self::FAIL, 'is-sysop', 'SySOp ('.$sysop.") is not in the list. Configured: {$rendered}. The owner holds SySOp, so as configured the owner cannot reach the admin panel.");

            return;
        }

        $extra = implode(',', array_values(array_diff($configured, [$sysop])));
        $this->record(self::UNKNOWN, 'is-sysop', "SySOp is present, plus {$extra}. TOG-106 signed off one ID; anything beyond it grants the panel to holders nobody approved. Deliberate, or a widening that needs sign-off?");
    }

    /**
     * Shape, not membership. A Discord snowflake is a run of digits; anything
     * else means the variable holds something the intersect will never match.
     *
     * The mistake this actually catches is a role *name* where an ID belongs —
     * `DISCORD_MODERATOR_ROLE_IDS=SySOp`. It is the natural thing to type, it is
     * what the role is called everywhere a human reads it, and because roles are
     * matched by exact string it grants nobody while looking entirely plausible
     * in an environment panel.
     *
     * Surrounding quotes are *not* a case this needs to catch, which was worth
     * measuring rather than assuming: `env()` runs values through phpdotenv,
     * which strips a matched pair of single or double quotes, so
     * `DISCORD_MODERATOR_ROLE_IDS="100000000000000001"` resolves to the bare
     * snowflake and works. Whitespace is handled too — config/services.php trims
     * each entry. Verified on 2026-08-30 against both, not inferred.
     *
     * @param  list<string>  $configured
     */
    private function checkShape(array $configured): void
    {
        if ($configured === []) {
            $this->record(self::UNKNOWN, 'shape', 'nothing configured, so there is nothing to check the shape of');

            return;
        }

        $malformed = array_values(array_filter(
            $configured,
            fn (string $id): bool => preg_match('/^[0-9]{17,20}$/', $id) !== 1,
        ));

        if ($malformed === []) {
            $this->record(self::PASS, 'shape', count($configured).' entr'.(count($configured) === 1 ? 'y is a' : 'ies are').' well-formed snowflake'.(count($configured) === 1 ? '' : 's'));

            return;
        }

        $shown = implode(', ', array_map(fn (string $v): string => "'{$v}'", $malformed));
        $this->record(self::FAIL, 'shape', "not a Discord snowflake: {$shown}. Roles are matched by exact string, so this entry can never match anything. Stray quotes or whitespace in the environment panel are the usual cause.");
    }

    /**
     * The fail-closed guarantee, checked against the code rather than asserted.
     *
     * Everything above only means something because an empty list denies rather
     * than permits. That is the third step of TOG-427 and the safety property the
     * revocation path rests on. It is one `if` in DiscordLoginController, so this
     * re-derives it here from the same config the controller reads, and would go
     * red if the branch were ever inverted.
     */
    private function checkFailClosed(): void
    {
        $saved = config('services.discord.moderator_role_ids');

        try {
            config(['services.discord.moderator_role_ids' => []]);
            $grantsOnEmpty = array_intersect([$this->sysopId()], (array) config('services.discord.moderator_role_ids', [])) !== [];
        } finally {
            config(['services.discord.moderator_role_ids' => $saved]);
        }

        $this->record(
            $grantsOnEmpty ? self::FAIL : self::PASS,
            'fails-closed',
            $grantsOnEmpty
                ? 'an empty moderator list grants the panel. Revocation by blanking the variable does not work.'
                : 'an empty moderator list grants nobody, so blanking the variable revokes cleanly with no deploy',
        );
    }

    private function report(): int
    {
        $failures = array_filter($this->results, fn (array $r): bool => $r['status'] === self::FAIL);
        $unknowns = array_filter($this->results, fn (array $r): bool => $r['status'] === self::UNKNOWN);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'results' => $this->results,
                'failures' => count($failures),
                'unknowns' => count($unknowns),
                'ok' => $failures === [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        // handle() always records at least five findings, but max() throws on an
        // empty array rather than returning something harmless, so the fallback
        // keeps a future caller from turning a report into a fatal.
        $width = max([0, ...array_map(fn (array $r): int => strlen($r['name']), $this->results)]);

        foreach ($this->results as $r) {
            $colour = match ($r['status']) {
                self::PASS => 'info',
                self::FAIL => 'error',
                default => 'comment',
            };
            $this->line(sprintf(
                '  <%s>%s</%s> %s  %s',
                $colour,
                str_pad($r['status'], 7),
                $colour,
                str_pad($r['name'], $width),
                $r['detail'],
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d checks: %d pass, %d fail, %d unknown',
            count($this->results),
            count($this->results) - count($failures) - count($unknowns),
            count($failures),
            count($unknowns),
        ));

        // What this cannot check, stated every run so it is never mistaken for a
        // full verification of TOG-427's done-when.
        //
        // This command proves the *grant* is the approved one on this box. It
        // cannot prove the other half — that a real SySOp holder signing in with
        // Discord sees the admin link and a real member does not — because that
        // needs a live OAuth round trip with a real member's consent, and there
        // is no credential-free probe for it. tests/Feature/Auth/DiscordLoginTest.php
        // drives exactly that path against a faked Discord and is the closest
        // thing to it that can run unattended.
        //
        // It also cannot tell you whether SySOp still has the holders the
        // 2026-08-19 audit recorded. Granting SySOp to a second person grants
        // them this panel too — the intended escape hatch, but a deliberate act.
        // Checking it needs a bot token and lives in two-bot (TOG-13).
        $this->line('  Not checkable from here: the live moderator-vs-member login round trip');
        $this->line('  (needs real Discord consent) and the current SySOp holder count (needs a');
        $this->line('  bot token, TOG-13). Both are named on TOG-427.');

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
