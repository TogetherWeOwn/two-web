/*
 * Was-this-helpful votes on the static FAQ page (TOG-8863). Dependency-free
 * on purpose: the site ships no Alpine and no toast system, and this is the
 * only interaction on the FAQ page, so a small module beats a new runtime.
 *
 * Page-scoped, not bundled: `AssetCompressionTest` pins `resources/js/app.js`
 * import-free so the global bundle stays tiny on every page. This module is
 * its own Vite entry loaded only by the FAQ page — same pattern as the event
 * copy-link (TOG-7262).
 *
 * Progressive enhancement: `/faq` is a session-free static leaf and renders
 * identically without JS. The vote rows stay `hidden` until this module boots
 * against the `web`-group index endpoint — which is what sets the session and
 * XSRF cookies and hands over the CSRF token the PUTs need. If the backend is
 * unreachable (or the app database is down), the rows stay hidden rather than
 * showing buttons that cannot work.
 *
 * Contract with `faq.blade.php`: the list container carries
 * `data-faq-votes="<index url>"`, every entry card carries
 * `data-faq-entry="<slug>"`, and each card holds one `[data-faq-vote]` row
 * with yes/no buttons, a thanks note and an error note.
 */

const VOTE_ROW = '[data-faq-vote]';

function paintRow(row, votedHelpful) {
    const yes = row.querySelector('[data-faq-vote-yes]');
    const no = row.querySelector('[data-faq-vote-no]');
    const thanks = row.querySelector('[data-faq-vote-thanks]');
    const prompt = row.querySelector('[data-faq-vote-prompt]');

    if (!yes || !no || !thanks || !prompt) {
        return;
    }

    const voted = votedHelpful === true || votedHelpful === false;

    prompt.classList.toggle('hidden', voted);
    thanks.classList.toggle('hidden', !voted);
    yes.setAttribute('aria-pressed', votedHelpful === true ? 'true' : 'false');
    no.setAttribute('aria-pressed', votedHelpful === false ? 'true' : 'false');

    if (voted) {
        thanks.textContent = votedHelpful
            ? 'Thanks — glad this helped. You can change your answer anytime.'
            : 'Thanks — we’ll work on this answer. You can change your vote anytime.';
    }
}

function showError(card, message) {
    const error = card.querySelector('[data-faq-vote-error]');

    if (!error) {
        return;
    }

    error.textContent = message;
    error.classList.remove('hidden');
    window.setTimeout(() => error.classList.add('hidden'), 5000);
}

async function boot() {
    const container = document.querySelector('[data-faq-votes]');

    if (!container) {
        return;
    }

    let csrfToken = null;
    let votes = {};

    try {
        const response = await fetch(container.getAttribute('data-faq-votes'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();
        csrfToken = typeof data.csrf_token === 'string' ? data.csrf_token : null;
        votes = data.votes && typeof data.votes === 'object' ? data.votes : {};
    } catch {
        return;
    }

    if (!csrfToken) {
        return;
    }

    const cards = document.querySelectorAll('[data-faq-entry]');

    cards.forEach((card) => {
        const row = card.querySelector(VOTE_ROW);

        if (!row) {
            return;
        }

        row.classList.remove('hidden');
        paintRow(row, votes[card.getAttribute('data-faq-entry')]);
    });

    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-faq-vote-yes], [data-faq-vote-no]')
            : null;

        if (!button) {
            return;
        }

        const card = button.closest('[data-faq-entry]');
        const row = button.closest(VOTE_ROW);

        if (!card || !row) {
            return;
        }

        event.preventDefault();
        button.disabled = true;

        try {
            const response = await fetch(
                `${container.getAttribute('data-faq-votes')}/${card.getAttribute('data-faq-entry')}`,
                {
                    method: 'PUT',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ helpful: button.hasAttribute('data-faq-vote-yes') }),
                },
            );

            if (response.status === 429) {
                showError(card, 'Too many votes — try again in a minute.');

                return;
            }

            if (!response.ok) {
                showError(card, 'That vote didn’t save — try again.');

                return;
            }

            const data = await response.json();
            paintRow(row, data.helpful === true);
        } catch {
            showError(card, 'That vote didn’t save — try again.');
        } finally {
            button.disabled = false;
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
