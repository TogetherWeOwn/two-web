<?php

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

test('clipboard denial falls back from the nested event icon and removes its textarea', function (bool $throws) {
    $event = Event::factory()->create([
        'title' => 'Synthetic clipboard fallback night',
        'status' => EventStatus::Published,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
    ]);
    $canonical = route('events.page', $event);
    $message = $throws ? "That link didn't copy — copy it from the address bar." : 'Event link copied.';

    // Snapshot the real published route, as in JoinBlockedWidgetTest. Only the
    // disposable local fixture gets this policy: no external browser requests.
    $html = $this->get($canonical)->assertOk()->getContent();
    $html = str_replace('<head>', '<head><meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; frame-src \'none\'; object-src \'none\'">', $html, $heads);
    expect($heads)->toBe(1);
    $fixture = 'dusk-event-clipboard-fallback-'.Str::uuid().'.html';
    $path = public_path($fixture);
    file_put_contents($path, $html);

    try {
        $this->browse(function (Browser $browser) use ($fixture, $canonical, $message, $throws) {
            $browser->visit('/'.$fixture)
                ->waitFor('[data-testid="event-copy-link"]')
                ->assertAttribute('[data-testid="event-copy-link"]', 'data-copy-link', $canonical)
                ->assertMissing('[data-testid="event-copy-toast"]');

            $browser->script(<<<'JS'
                const clipboard = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
                const execCommand = Object.getOwnPropertyDescriptor(document, 'execCommand');
                const probe = window.eventClipboardProbe = {calls: [], area: null, selected: null};
                Object.defineProperty(navigator, 'clipboard', {configurable: true, value: {
                    writeText(text) {
                        probe.calls.push({method: 'clipboard', text});
                        return Promise.reject(new DOMException('Synthetic clipboard denial', 'NotAllowedError'));
                    }
                }});
                Object.defineProperty(document, 'execCommand', {configurable: true, value(command) {
                    probe.area = document.activeElement;
                    probe.calls.push({method: 'execCommand', command, text: probe.area.value});
                    probe.selected = {
                        tag: probe.area.tagName, connected: probe.area.isConnected,
                        readonly: probe.area.readOnly, opacity: probe.area.style.opacity,
                        start: probe.area.selectionStart, end: probe.area.selectionEnd
                    };
                    if (probe.throws) throw new Error('Synthetic execCommand failure');
                    return true;
                }});
                probe.restore = () => {
                    if (clipboard) Object.defineProperty(navigator, 'clipboard', clipboard);
                    else delete navigator.clipboard;
                    if (execCommand) Object.defineProperty(document, 'execCommand', execCommand);
                    else delete document.execCommand;
                    delete window.eventClipboardProbe;
                };
            JS);

            try {
                $browser->script('window.eventClipboardProbe.throws = '.($throws ? 'true' : 'false').';');
                // A real WebDriver click on the nested SVG exercises closest(),
                // not a button click or a direct call to the private copy helper.
                $browser->click('[data-testid="event-copy-link"] svg')
                    ->waitForTextIn('[data-testid="event-copy-toast"]', $message)
                    ->assertVisible('[data-testid="event-copy-toast"]')
                    ->assertAttribute('[data-testid="event-copy-toast"]', 'role', 'status');

                $result = $browser->script(<<<'JS'
                    const probe = window.eventClipboardProbe;
                    const toast = document.querySelector('[data-testid="event-copy-toast"]');
                    return {
                        calls: probe.calls, selected: probe.selected,
                        removed: probe.area !== null && !probe.area.isConnected,
                        textareas: document.querySelectorAll('textarea').length,
                        success: toast.classList.contains('bg-online-quiet'),
                        failure: toast.classList.contains('bg-alert-quiet')
                    };
                JS)[0];

                expect($result['calls'])->toHaveCount(2);
                expect($result['calls'][0]['method'])->toBe('clipboard');
                expect($result['calls'][0]['text'])->toBe($canonical);
                expect($result['calls'][1]['method'])->toBe('execCommand');
                expect($result['calls'][1]['command'])->toBe('copy');
                expect($result['calls'][1]['text'])->toBe($canonical);
                expect($result['selected']['tag'])->toBe('TEXTAREA');
                expect($result['selected']['connected'])->toBeTrue();
                expect($result['selected']['readonly'])->toBeTrue();
                expect($result['selected']['opacity'])->toBe('0');
                expect($result['selected']['start'])->toBe(0);
                expect($result['selected']['end'])->toBe(strlen($canonical));
                expect($result['removed'])->toBeTrue();
                expect($result['textareas'])->toBe(0);
                expect($result['success'])->toBe(! $throws);
                expect($result['failure'])->toBe($throws);
            } finally {
                $browser->script('window.eventClipboardProbe?.restore();');
                $browser->driver->navigate()->to('about:blank');
            }
        });
    } finally {
        unlink($path);
    }
})->with(['execCommand succeeds' => false, 'execCommand throws' => true]);
