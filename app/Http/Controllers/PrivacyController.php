<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * `/privacy` — the public privacy policy (TOG-8609).
 *
 * Registered in routes/funnel.php with an empty middleware stack, same as
 * `/about` (TOG-6853) and `/faq` (TOG-8396): SESSION_DRIVER=database in every
 * environment we ship, so a `web`-group route opens Postgres in StartSession
 * before the view runs. The one file read here is from disk — the versioned
 * policy source — so the page stays 200 during an app-DB outage.
 *
 * A policy change ships as a NEW content file (privacy-policy-v2.md, …): point
 * POLICY_FILE and POLICY_VERSION at it, and the old file stays in git history
 * so members can see what changed. The version renders on the page, so a
 * stale-file deploy reads wrong loudly instead of silently.
 */
final class PrivacyController
{
    public const POLICY_FILE = 'content/privacy-policy-v1.md';

    public const POLICY_VERSION = '1';

    public function __invoke(): View
    {
        $markdown = file_get_contents(base_path(self::POLICY_FILE));

        return view('privacy', [
            'policyHtml' => Str::markdown($markdown === false ? '' : $markdown),
            'policyVersion' => self::POLICY_VERSION,
        ]);
    }
}
