import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

// Execute the shipped component script, not a second implementation.
const blade = readFileSync(new URL('../resources/views/livewire/member-profile.blade.php', import.meta.url), 'utf8');
const script = blade.match(/@script[\s\S]*?<script>([\s\S]*?)<\/script>/)[1];
const key = 'two:profile-draft:42';
const input = { bio: 'Unsent <bio>\nsecond line', gamesText: 'Chess\nCo-op', timezone: 'Europe/London' };

function page({ stored = null, owner = true, storageFails = false, restoreFails = false, form = true } = {}) {
    const storage = new Map(stored === null ? [] : [[key, stored]]);
    const nodes = Object.fromEntries(Object.entries(input).map(([name, value]) => [name, { value }]));
    const warning = { hidden: true, focus() { this.focused = true; } };
    let request;
    let click;
    const restores = [];
    const navigations = [];
    const wire = {
        el: {
            dataset: { profileId: '42', draftOwner: owner ? '1' : '0', loginUrl: '/login?next=%2Fmembers%2F42' },
            querySelector(selector) {
                if (selector === '[data-testid="profile-edit-form"]') return form ? {} : null;
                if (selector === '[data-testid="profile-draft-unavailable"]') return warning;
                return nodes[{ '#bio': 'bio', '#games': 'gamesText', '#timezone': 'timezone' }[selector]] ?? null;
            },
            addEventListener(name, handler) { if (name === 'click') click = handler; },
        },
        on() {},
        $hook(name, handler) { if (name === 'request') request = handler; },
        restoreDraft(...values) {
            restores.push(values);
            return restoreFails ? Promise.reject(new Error('offline')) : Promise.resolve();
        },
    };
    runInNewContext(script, {
        $wire: wire,
        window: { location: { assign(url) { navigations.push(url); } } },
        sessionStorage: {
            getItem(k) { if (storageFails) throw new Error('disabled'); return storage.get(k) ?? null; },
            setItem(k, v) { if (storageFails) throw new Error('quota'); storage.set(k, v); },
            removeItem(k) { if (storageFails) throw new Error('disabled'); storage.delete(k); },
        },
        Date, JSON,
    });
    return {
        storage, warning, restores, navigations,
        fail(status) {
            let prevented = false;
            assert.ok(request, 'missing request hook');
            request({ fail(handler) { handler({ status, preventDefault() { prevented = true; } }); } });
            return prevented;
        },
        login() {
            let prevented = false;
            assert.ok(click, 'missing login-link draft handler');
            click({ target: { closest() { return {}; } }, preventDefault() { prevented = true; } });
            return prevented;
        },
    };
}

const draft = () => JSON.stringify({ version: 1, savedAt: Date.now(), ...input });
const settle = () => new Promise(resolve => setImmediate(resolve));

test('419 captures deferred DOM input before navigating and restores it after login', async () => {
    const expired = page();
    assert.equal(expired.fail(419), true);
    assert.deepEqual(JSON.parse(expired.storage.get(key)), { version: 1, savedAt: JSON.parse(expired.storage.get(key)).savedAt, ...input });
    assert.deepEqual(expired.navigations, ['/login?next=%2Fmembers%2F42']);
    const returned = page({ stored: expired.storage.get(key), form: false });
    await settle();
    assert.deepEqual(Array.from(returned.restores[0] ?? []), Object.values(input));
    assert.equal(returned.storage.has(key), false);
});

test('other HTTP failures keep Livewire default handling and never navigate', () => {
    for (const status of [401, 403, 422, 500]) {
        const p = page();
        assert.equal(p.fail(status), false);
        assert.equal(p.storage.size, 0);
        assert.equal(p.navigations.length, 0);
    }
});

test('storage refusal keeps input on-screen with a focused copy-before-login warning', () => {
    const p = page({ storageFails: true });
    assert.equal(p.fail(419), true);
    assert.equal(p.navigations.length, 0);
    assert.equal(p.warning.hidden, false);
    assert.equal(p.warning.focused, true);
});

test('server-rendered expiry login link also preserves the draft', () => {
    const p = page();
    assert.equal(p.login(), false);
    assert.deepEqual(JSON.parse(p.storage.get(key)).bio, input.bio);
    const refused = page({ storageFails: true });
    assert.equal(refused.login(), true);
    assert.equal(refused.warning.hidden, false);
});

test('different signed-in member cannot restore the original owners draft', async () => {
    const p = page({ stored: draft(), owner: false });
    await settle();
    assert.equal(p.restores.length, 0);
    assert.equal(p.storage.size, 0);
});

test('malformed, obsolete, or non-string drafts are discarded', async () => {
    for (const stored of ['{', '{}', JSON.stringify({ version: 1, savedAt: 0, ...input }), JSON.stringify({ version: 1, savedAt: Date.now(), ...input, bio: {} })]) {
        const p = page({ stored });
        await settle();
        assert.equal(p.restores.length, 0);
        assert.equal(p.storage.size, 0);
    }
});

test('draft remains recoverable if the restore request fails', async () => {
    const p = page({ stored: draft(), restoreFails: true, form: false });
    await settle();
    assert.equal(p.restores.length, 1);
    assert.equal(p.storage.has(key), true);
});

test('419 without an open form goes to login without inventing a draft', () => {
    const p = page({ form: false });
    assert.equal(p.fail(419), true);
    assert.equal(p.storage.size, 0);
    assert.equal(p.navigations.length, 1);
});
