<?php

namespace App\Services\Paperclip;

use App\Services\Paperclip\Exceptions\RestartCommandNotPinnedException;

/**
 * The allowlist of cold bot settings that may file an operator restart card, and
 * the fixed content each one produces.
 *
 * A *cold* setting is read once at bot boot, so changing it in the admin panel
 * does nothing until the bot process restarts (docs/cold-setting-restart-cards
 * .md). Every entry here is an allowlist member: a setting that is not one of
 * these cases cannot file a card at all, which is the structural guarantee that
 * no caller-supplied string ever reaches the board. The restart command and its
 * rollback are constants, one per setting — there is exactly one right command,
 * so it is written down once and never re-improvised per save.
 */
enum ColdSetting: string
{
    case TWO_AUTOMOD = 'TWO_AUTOMOD';

    /**
     * The exact host command that restarts the bot so this setting takes effect,
     * and the command that rolls the restart back — or null while it is not yet
     * pinned.
     *
     * Host-ops knowledge is not fabricated here (docs Decision 4). Until the
     * operator provisioning card (TOG-3573) pins the canonical two-bot Coolify
     * restart and rollback, this stays null and restartCard() fails closed
     * rather than filing a card that tells a human to run a guessed command.
     * Pinning it is a one-line edit to the case below and nowhere else.
     *
     * @return array{restart: string, rollback: string}|null
     */
    // @phpstan-ignore-next-line return.unusedType (TOG-3573 adds the first pinned case; until then this arm is intentionally always null)
    public function commands(): ?array
    {
        return match ($this) {
            // TOG-3573 pins these. Do not guess them: a wrong restart command on
            // an operator card is worse than an unfiled one.
            self::TWO_AUTOMOD => null,
        };
    }

    /** Whether this setting's restart command has been pinned (TOG-3573). */
    public function isPinned(): bool
    {
        return $this->commands() !== null;
    }

    /**
     * The fixed operator card for a change of this setting to $newValue.
     *
     * $newValue is the caller's only variable input and must already be a
     * validated setting value (a bool or an enum rendered to a string), never
     * free text — it lands inside a code span in the title and body, but the
     * allowlist above is what guarantees only real cold settings reach here.
     *
     * @throws RestartCommandNotPinnedException when the command is not pinned yet
     */
    public function restartCard(string $newValue): RestartCard
    {
        $commands = $this->commands();

        if ($commands === null) {
            throw RestartCommandNotPinnedException::for($this);
        }

        return new RestartCard(
            title: "Operator: restart TWO bot to apply {$this->value}={$newValue}",
            body: $this->body($newValue, $commands['restart'], $commands['rollback']),
        );
    }

    private function body(string $newValue, string $restart, string $rollback): string
    {
        return <<<MD
            A cold bot setting was changed in the admin panel and needs a bot restart to take effect.

            - **Setting:** `{$this->value}`
            - **New value:** `{$newValue}`

            ## Apply

            ```
            {$restart}
            ```

            ## Rollback

            ```
            {$rollback}
            ```

            Filed automatically by two-web (TOG-3537). See `docs/cold-setting-restart-cards.md` for the decision record.
            MD;
    }
}
