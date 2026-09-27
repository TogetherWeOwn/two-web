/*
 * Copy-profile-link (TOG-6926). Dependency-free on purpose: the site ships no
 * Alpine and no toast system, and this is the only clipboard interaction, so a
 * small module beats a new runtime.
 *
 * Page-scoped, not bundled: `AssetCompressionTest` pins `resources/js/app.js`
 * import-free so the global bundle stays tiny on every page. This module is its
 * own Vite entry loaded only by the member profile page.
 *
 * Wiring is document-level delegation, not per-button binding, because the
 * button lives inside a Livewire component: every edit/save re-renders the
 * header and would drop a directly attached listener. Delegation survives
 * morphs. The toast lives outside the component (`profiles.show`) for the same
 * reason — Livewire can never wipe it mid-announcement.
 *
 * `navigator.clipboard.writeText` only exists in secure contexts (and can be
 * denied), so the fallback is the classic hidden-textarea + execCommand path
 * for http origins and older browsers. Both paths converge on the same toast.
 */

let toastTimer = 0;

function showToast(message, failed) {
    const toast = document.querySelector('[data-testid="profile-copy-toast"]');

    if (!toast) {
        return;
    }

    // The role stays `status` as rendered and is never toggled here: a failed
    // copy is advice ("copy it from the address bar"), not an interruption, and
    // flipping live-region roles at runtime is inconsistently announced.
    toast.textContent = message;
    toast.classList.remove('hidden', ...(failed ? ['bg-online-quiet'] : ['bg-alert-quiet']));
    toast.classList.add(...(failed ? ['bg-alert-quiet'] : ['bg-online-quiet']));

    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => {
        toast.classList.add('hidden');
    }, 4000);
}

async function copyText(text) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // Permission denied or transient failure: fall through to execCommand.
        }
    }

    if (typeof document.execCommand !== 'function') {
        return false;
    }

    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    area.setSelectionRange(0, area.value.length);

    let copied = false;

    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }

    area.remove();

    return copied;
}

document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-copy-link]') : null;

    if (!button) {
        return;
    }

    event.preventDefault();

    const copied = await copyText(button.getAttribute('data-copy-link') || '');

    showToast(copied ? 'Profile link copied.' : "That link didn't copy — copy it from the address bar.", !copied);
});
