<?php

namespace App\Services\Bot;

/**
 * The deliberately small dashboard allowlist for the first settings slice.
 *
 * Capability gates and secrets cannot appear here: the page can only construct
 * controls by iterating these cases, while two-bot independently refuses every
 * env-only or unknown key.
 */
enum BotSettingKey: string
{
    case LandingChannelIds = 'DISCORD_LANDING_CHANNEL_IDS';
    case AnchorWelcomeChannelId = 'DISCORD_ANCHOR_WELCOME_CHANNEL_ID';
    case GoodbyeChannelIds = 'DISCORD_GOODBYE_CHANNEL_IDS';
    case SessionLobbyVoiceChannelId = 'DISCORD_SESSION_LOBBY_VOICE_CHANNEL_ID';
    case SessionLookingToPlayChannelId = 'DISCORD_SESSION_LOOKING_TO_PLAY_CHANNEL_ID';
    case OnboardingDryRun = 'TWO_ONBOARDING_DRY_RUN';

    case Automod = 'TWO_AUTOMOD';
    case AutomodEnforce = 'TWO_AUTOMOD_ENFORCE';
    case AutomodBadWords = 'TWO_AUTOMOD_BAD_WORDS';
    case AutomodBlockedAttachmentExtensions = 'TWO_AUTOMOD_BLOCKED_ATTACHMENT_EXTENSIONS';
    case AutomodAllowedDomains = 'TWO_AUTOMOD_ALLOWED_DOMAINS';
    case AutomodRepeatCount = 'TWO_AUTOMOD_REPEAT_COUNT';
    case AutomodRepeatWindowSeconds = 'TWO_AUTOMOD_REPEAT_WINDOW_SECONDS';
    case AutomodMentionLimit = 'TWO_AUTOMOD_MENTION_LIMIT';
    case AutomodBypassRoleIds = 'TWO_AUTOMOD_BYPASS_ROLE_IDS';
    case AutomodExemptChannelIds = 'TWO_AUTOMOD_EXEMPT_CHANNEL_IDS';
    case AutomodSanctions = 'TWO_AUTOMOD_SANCTIONS';

    public function group(): string
    {
        return match ($this) {
            self::LandingChannelIds,
            self::AnchorWelcomeChannelId,
            self::GoodbyeChannelIds,
            self::SessionLobbyVoiceChannelId,
            self::SessionLookingToPlayChannelId,
            self::OnboardingDryRun => 'Onboarding',
            default => 'Automod',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LandingChannelIds => 'Landing channel IDs',
            self::AnchorWelcomeChannelId => 'Anchor welcome channel ID',
            self::GoodbyeChannelIds => 'Goodbye channel IDs',
            self::SessionLobbyVoiceChannelId => 'Session lobby voice channel ID',
            self::SessionLookingToPlayChannelId => 'Looking-to-play channel ID',
            self::OnboardingDryRun => 'Onboarding dry run',
            self::Automod => 'Automod master switch',
            self::AutomodEnforce => 'Enforce automod actions',
            self::AutomodBadWords => 'Blocked words',
            self::AutomodBlockedAttachmentExtensions => 'Blocked attachment extensions',
            self::AutomodAllowedDomains => 'Allowed domains',
            self::AutomodRepeatCount => 'Repeated-message count',
            self::AutomodRepeatWindowSeconds => 'Repeated-message window (seconds)',
            self::AutomodMentionLimit => 'Mention limit',
            self::AutomodBypassRoleIds => 'Bypass role IDs',
            self::AutomodExemptChannelIds => 'Exempt channel IDs',
            self::AutomodSanctions => 'Sanctions',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LandingChannelIds => 'Preferred welcome channels, in order. One Discord channel ID per line.',
            self::AnchorWelcomeChannelId => 'Optional channel for the routed Sunday Squad welcome. Leave blank to disable it.',
            self::GoodbyeChannelIds => 'Session-mode goodbye destinations. One Discord channel ID per line.',
            self::SessionLobbyVoiceChannelId => 'Session-mode destination for “Join voice now”. Leave blank to disable it.',
            self::SessionLookingToPlayChannelId => 'Session-mode destination for “Find people to play with”. Leave blank to disable it.',
            self::OnboardingDryRun => 'Observe and audit onboarding without legacy role writes.',
            self::Automod => 'Builds automod cache policy at process start.',
            self::AutomodEnforce => 'Off keeps automod in dry-run; on performs configured sanctions.',
            self::AutomodBadWords => 'One case-insensitive word or phrase per line.',
            self::AutomodBlockedAttachmentExtensions => 'One extension per line, with or without a leading dot.',
            self::AutomodAllowedDomains => 'One lower-case domain per line.',
            self::AutomodRepeatCount => 'Matching messages needed before the repeated-message rule fires.',
            self::AutomodRepeatWindowSeconds => 'Window used by the repeated-message rule.',
            self::AutomodMentionLimit => 'Maximum mentions allowed in one message.',
            self::AutomodBypassRoleIds => 'Roles exempt from automod. One Discord role ID per line.',
            self::AutomodExemptChannelIds => 'Channels exempt from automod. One Discord channel ID per line.',
            self::AutomodSanctions => 'Comma-separated threshold actions, for example 1:delete,2:warn,3:timeout:600.',
        };
    }

    /** boolean, integer, snowflake, snowflakes, lines, or sanctions. */
    public function kind(): string
    {
        return match ($this) {
            self::OnboardingDryRun, self::Automod, self::AutomodEnforce => 'boolean',
            self::AutomodRepeatCount,
            self::AutomodRepeatWindowSeconds,
            self::AutomodMentionLimit => 'integer',
            self::AnchorWelcomeChannelId,
            self::SessionLobbyVoiceChannelId,
            self::SessionLookingToPlayChannelId => 'snowflake',
            self::LandingChannelIds,
            self::GoodbyeChannelIds,
            self::AutomodBypassRoleIds,
            self::AutomodExemptChannelIds => 'snowflakes',
            self::AutomodSanctions => 'sanctions',
            default => 'lines',
        };
    }

    public function minimum(): ?int
    {
        return match ($this) {
            self::AutomodRepeatCount => 2,
            self::AutomodRepeatWindowSeconds, self::AutomodMentionLimit => 1,
            default => null,
        };
    }

    public function maximum(): ?int
    {
        return match ($this) {
            self::AutomodRepeatCount => 20,
            self::AutomodRepeatWindowSeconds => 3600,
            self::AutomodMentionLimit => 50,
            default => null,
        };
    }

    public function isEditable(): bool
    {
        // The ADR requires a successful cold-setting save to create an operator
        // restart card. Keep the only cold key read-only until that workflow lands.
        return $this !== self::Automod;
    }

    public function restartNotice(): string
    {
        if ($this === self::Automod) {
            return 'Read-only for now. This master switch requires a restart and an operator restart card.';
        }

        return 'Currently applies on the next restart: this key is safe for hot reload, but its consumer is not live-wired yet.';
    }

    public function toFormValue(mixed $value): mixed
    {
        return match ($this->kind()) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true ? '1' : '0',
            'integer' => is_numeric($value) ? (int) $value : null,
            'snowflakes', 'lines' => implode("\n", $this->listValue($value)),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    public function toStoredValue(mixed $value): mixed
    {
        return match ($this->kind()) {
            'boolean' => (string) $value === '1',
            'integer' => (int) $value,
            'snowflakes', 'lines' => $this->listValue($value),
            default => trim((string) $value),
        };
    }

    /** @return list<string> */
    private function listValue(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : preg_split('/[\r\n,]+/', (string) $value);

        if ($items === false) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $items),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
