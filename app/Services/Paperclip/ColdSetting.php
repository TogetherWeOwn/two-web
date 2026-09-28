<?php

namespace App\Services\Paperclip;

/**
 * The allowlist of cold bot settings that may file an operator restart card, and
 * the fixed content each one produces.
 *
 * A *cold* setting is read once at bot boot, so changing it in the admin panel
 * does nothing until the bot process restarts (docs/cold-setting-restart-cards
 * .md). Every entry here is an allowlist member: a setting that is not one of
 * these cases cannot file a card at all, which is the structural guarantee that
 * no caller-supplied string ever reaches the board. The restart command is a
 * constant per setting and target environment — there is exactly one right
 * command, so it is written down once and never re-improvised per save.
 */
enum ColdSetting: string
{
    case TWO_AUTOMOD = 'TWO_AUTOMOD';

    /**
     * The exact command that restarts the bot so this setting takes effect.
     *
     * Pinned from the operator provisioning card (TOG-3573): a Coolify API
     * restart of the two-bot application. $COOLIFY_URL and $COOLIFY_TOKEN are
     * shell variables on the runner (the DevOps agent holds a deploy-scope
     * token); nothing secret is written here or onto the card.
     */
    public function restartCommand(BotEnvironment $environment): string
    {
        return match ($this) {
            self::TWO_AUTOMOD => 'curl -fsS -X POST -H "Authorization: Bearer $COOLIFY_TOKEN" '
                .'"$COOLIFY_URL/api/v1/applications/'.$environment->coolifyAppUuid().'/restart"',
        };
    }

    /**
     * The fixed operator card for a change of this setting to $newValue.
     *
     * $newValue is the caller's only variable input and must already be a
     * validated setting value (a bool or an enum rendered to a string), never
     * free text — it lands inside a code span in the title and body, but the
     * allowlist above is what guarantees only real cold settings reach here.
     */
    public function restartCard(string $newValue, BotEnvironment $environment): RestartCard
    {
        return new RestartCard(
            title: "Operator: restart TWO bot ({$environment->value}) to apply {$this->value}={$newValue}",
            body: $this->body($newValue, $environment, $this->restartCommand($environment)),
        );
    }

    private function body(string $newValue, BotEnvironment $environment, string $restart): string
    {
        return <<<MD
            A cold bot setting was changed in the admin panel and needs a bot restart to take effect.

            - **Setting:** `{$this->value}`
            - **New value:** `{$newValue}`
            - **Bot:** two-bot {$environment->value} (Coolify app `{$environment->coolifyAppUuid()}`)

            ## Apply

            ```
            {$restart}
            ```

            ## Rollback

            Set `{$this->value}` back to its previous value in the two-web admin panel, then run the same restart command again. No image or deploy change is involved.

            ```
            {$restart}
            ```

            Filed automatically by two-web (TOG-3537). See `docs/cold-setting-restart-cards.md` for the decision record.
            MD;
    }
}
