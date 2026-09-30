import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

// Execute the shipped component script, not a second implementation.
const blade = readFileSync(new URL('../resources/views/livewire/member-profile.blade.php', import.meta.url), 'utf8');
const script = blade.match(/@script[\s\S]*?<script>([\s\S]*?)<\/script>/)[1];
const layout = readFileSync(new URL('../resources/views/components/layouts/app.blade.php', import.meta.url), 'utf8');
const authScript = layout.match(/<script data-testid="auth-tab-sync">([\s\S]*?)<\/script>/)[1]
    .replace(/@js\(route\('auth.status'\)\)/, '"/auth/status"');
const key = 'two:profile-draft:42';
const input = { bio: 'Unsent <bio>\nsecond line', gamesText: 'Chess\nCo-op', timezone: 'Europe/London' };

function page({ stored = null, owner = true, storageFails = false, restoreFails = false, manualRestore = false, form = true } = {}) {
    const storage = new Map(stored === null ? [] : [[key, stored]]);
    const nodes = Object.fromEntries(Object.entries(input).map(([name, value]) => [name, { value }]));
    const warning = { hidden: true, focus() { this.focused = true; } };
    let request;
    let click;
    let pendingRestore = null;
    const restores = [];
    const navigations = [];
    const document = Object.assign(new EventTarget(), { visibilityState: 'visible' });
    const window = Object.assign(new EventTarget(), {
        localStorage: { setItem() {} },
        location: { assign(url) { navigations.push(url); }, reload() { navigations.push('reload'); } },
    });
    const wire = {
        el: {
            isConnected: true,
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
            if (manualRestore) {
                return new Promise((resolve) => { pendingRestore = resolve; });
            }
            return restoreFails ? Promise.reject(new Error('offline')) : Promise.resolve();
        },
    };
    const context = {
        $wire: wire,
        window, document, CustomEvent,
        fetch: async () => ({ ok: true, json: async () => ({ authenticated: false }) }),
        sessionStorage: {
            getItem(k) { if (storageFails) throw new Error('disabled'); return storage.get(k) ?? null; },
            setItem(k, v) { if (storageFails) throw new Error('quota'); storage.set(k, v); },
            removeItem(k) { if (storageFails) throw new Error('disabled'); storage.delete(k); },
        },
        Date, JSON,
    };
    runInNewContext(script, context);
    runInNewContext(authScript, context);
    return {
        storage, warning, restores, navigations,
        resolveRestore() {
            assert.ok(pendingRestore, 'no pending restore');
            pendingRestore();
        },
        authExpiry(source) {
            if (source === 'focus') window.dispatchEvent(new Event('focus'));
            else if (source === 'visibility') document.dispatchEvent(new Event('visibilitychange'));
            else if (source === 'pageshow') window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
            else window.dispatchEvent(Object.assign(new Event('storage'), { key: 'two-auth', newValue: 'signed-out' }));
        },
        detach() { wire.el.isConnected = false; },
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

for (const source of ['focus', 'visibility', 'pageshow', 'storage']) {
    test(`${source} auth expiry preserves deferred input before login instead of reloading`, async () => {
        const p = page();
        p.authExpiry(source);
        await settle();
        assert.equal(JSON.parse(p.storage.get(key)).bio, input.bio);
        assert.deepEqual(p.navigations, ['/login?next=%2Fmembers%2F42']);
        const returned = page({ stored: p.storage.get(key), form: false });
        await settle();
        assert.deepEqual(Array.from(returned.restores[0] ?? []), Object.values(input));
    });

    test(`${source} auth expiry cannot discard input when storage is unavailable`, async () => {
        const p = page({ storageFails: true });
        p.authExpiry(source);
        await settle();
        assert.deepEqual(p.navigations, []);
        assert.equal(p.warning.hidden, false);
        assert.equal(p.warning.focused, true);
    });

    test(`${source} auth expiry still reloads pages without an open profile form`, async () => {
        const p = page({ form: false });
        p.authExpiry(source);
        await settle();
        assert.deepEqual(p.navigations, ['reload']);
        assert.equal(p.storage.size, 0);
    });
}

test('a detached profile cannot interfere with the current pages auth reload', async () => {
    const p = page();
    p.detach();
    p.authExpiry('focus');
    await settle();
    assert.deepEqual(p.navigations, ['reload']);
    assert.equal(p.storage.size, 0);
});

for (const source of ['focus', 'visibility', 'pageshow', 'storage']) {
    test(`${source} auth navigation during a pending restore retains the only stored copy`, async () => {
        const p = page({ stored: draft(), manualRestore: true, form: false });
        assert.equal(p.restores.length, 1);
        p.authExpiry(source);
        await settle();
        assert.deepEqual(p.navigations, ['reload']);
        p.resolveRestore();
        await settle();
        assert.equal(p.storage.has(key), true);
        const returned = page({ stored: p.storage.get(key) });
        await settle();
        assert.deepEqual(Array.from(returned.restores[0] ?? []), Object.values(input));
        assert.equal(returned.storage.has(key), false);
    });
}

test('419 without an open form goes to login without inventing a draft', () => {
    const p = page({ form: false });
    assert.equal(p.fail(419), true);
    assert.equal(p.storage.size, 0);
    assert.equal(p.navigations.length, 1);
});
