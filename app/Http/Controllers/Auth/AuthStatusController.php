<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The cross-tab sign-out probe (TOG-8136).
 *
 * Logout destroys the session server-side, but a second tab keeps rendering
 *
 * @auth controls until its next full load — the next click there 302s with no
 * explanation. The layout's tab-sync script asks here on visibility/focus, and
 * a `false` reloads the tab into the guest render.
 *
 * Deliberately public: a logged-out tab must get `200 {"authenticated":false}`,
 * not the `auth`-middleware 302 to the Discord handoff. It answers one boolean
 * and nothing member-identifying, so there is nothing to leak; `no-store` keeps
 * a shared cache from ever serving one member's `true` to another tab.
 */
class AuthStatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => $request->user() instanceof User,
        ])->header('Cache-Control', 'no-store, private');
    }
}
