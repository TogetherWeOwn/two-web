<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\MemberStatsSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProfileController
{
    public function mine(Request $request, MemberStatsSource $stats): View
    {
        $member = $request->user();
        abort_unless($member instanceof User, 403);

        return $this->render($member, $stats);
    }

    public function show(User $user, MemberStatsSource $stats): View
    {
        Gate::authorize('view', $user);

        return $this->render($user, $stats);
    }

    private function render(User $member, MemberStatsSource $stats): View
    {
        $member->loadMissing('profile');
        $profile = $member->profile ?? new Profile([
            'user_id' => $member->getKey(),
            'games' => [],
        ]);

        return view('profiles.show', [
            'member' => $member,
            'profile' => $profile,
            'stats' => $stats->forMember($member->discord_id),
        ]);
    }
}
