<?php

// TOG-6928: /join carries a Discord widget embed for the live look, but the
// page converts without it. The what-to-expect panel and a working invite link
// are always in the HTML; the iframe is present exactly when the guild id can
// build one.
//
// The contract halves: DiscordWidgetTest (Unit, DB-free) pins the URL builder;
// below pins the rendered page. A missing/disabled widget must read as a
// complete page, never as a hole where an iframe used to be.

it('renders the widget beside an always-present fallback', function () {
    config()->set('services.discord.guild_id', '326474832151838730');
    config()->set('services.discord.invite_url', 'https://discord.gg/testinvite');

    $html = (string) $this->get(route('join'))->assertOk()->getContent();

    // The live look, with the performance and privacy attributes that keep it
    // out of the first-paint path and off Discord's referrer logs.
    expect($html)->toContain('data-testid="join-widget"')
        ->toContain('https://discord.com/widget?id=326474832151838730&amp;theme=dark')
        ->toContain('loading="lazy"')
        ->toContain('referrerpolicy="no-referrer"');

    // The fallback: what-to-expect copy plus a second working invite link.
    // `join-fallback-invite` is the widget-section door; `invite-link` is the
    // hero one. Both must answer with the real invite.
    expect($html)->toContain('data-testid="join-expect"')
        ->toContain(__('join.expect_heading'), escape: false)
        ->toContain('data-testid="join-fallback-invite"')
        ->toContain('data-testid="invite-link"')
        ->toContain('https://discord.gg/testinvite');

    // Every promised step renders — a renamed lang key must go red here, not
    // ship a half-empty panel.
    foreach (__('join.expect') as $step) {
        expect($html)->toContain($step);
    }
});

it('still converts with no hole when the widget cannot be built', function () {
    // Widget disabled server-side, and separately a misconfigured guild id:
    // both must render the same complete page with no iframe element at all.
    foreach ([null, '', 'not-a-snowflake'] as $guildId) {
        config()->set('services.discord.guild_id', $guildId);
        config()->set('services.discord.invite_url', 'https://discord.gg/testinvite');

        $html = (string) $this->get(route('join'))->assertOk()->getContent();

        expect($html)->not->toContain('data-testid="join-widget"')
            ->not->toContain('discord.com/widget')
            ->toContain('data-testid="join-expect"')
            ->toContain('data-testid="join-fallback-invite"')
            ->toContain('data-testid="one-click-join"')
            ->toContain('data-testid="invite-link"')
            ->toContain('https://discord.gg/testinvite');
    }
});

it('pins every fallback copy key so a rename fails loudly', function () {
    foreach (['widget_title', 'widget_note', 'expect_heading'] as $key) {
        expect(__('join.'.$key))->not->toBe('join.'.$key, "join.{$key} is missing");
    }

    expect(__('join.expect'))->toBeArray()->not->toBeEmpty();
});
