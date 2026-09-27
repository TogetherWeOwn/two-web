<?php

use App\Support\DiscordWidget;

// TOG-6928: the /join widget embed is built from the guild id at render time.
// A bad guild id must mean *no iframe*, never an iframe pointed at a URL
// Discord would 404 — the static fallback converts either way.
//
// Pure string logic, no database, so these live in Unit: this box cannot
// reach Postgres, and the builder must not need it anyway.

it('builds the dark widget URL for the guild id', function () {
    // A counting-pattern fake snowflake: the shape is what matters, and no
    // literal guild id belongs in this file (gitleaks discord-client-id).
    expect(DiscordWidget::url('900000000000000001'))
        ->toBe('https://discord.com/widget?id=900000000000000001&theme=dark');
});

it('returns null instead of a broken embed for a missing or hostile guild id', function () {
    // '123' and the 21-digit run pin the snowflake-length contract: digit
    // runs outside 17-20 mean no iframe, never a URL Discord would 404.
    foreach ([null, '', '   ', 'not-a-snowflake', '123', '900000000000000001234', '1234;alert(1)', 'https://evil.example.com/x'] as $guildId) {
        expect(DiscordWidget::url($guildId))->toBeNull("{$guildId} should mean no iframe");
    }
});
