<?php

use App\Support\DiscordWidget;

// TOG-6928: the /join widget embed is built from the guild id at render time.
// A bad guild id must mean *no iframe*, never an iframe pointed at a URL
// Discord would 404 — the static fallback converts either way.
//
// Pure string logic, no database, so these live in Unit: this box cannot
// reach Postgres, and the builder must not need it anyway.

it('builds the dark widget URL for the guild id', function () {
    expect(DiscordWidget::url('326474832151838730'))
        ->toBe('https://discord.com/widget?id=326474832151838730&theme=dark');
});

it('returns null instead of a broken embed for a missing or hostile guild id', function () {
    foreach ([null, '', '   ', 'not-a-snowflake', '1234;alert(1)', 'https://evil.example.com/x'] as $guildId) {
        expect(DiscordWidget::url($guildId))->toBeNull("{$guildId} should mean no iframe");
    }
});
