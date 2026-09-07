<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $viewer, User $member): bool
    {
        // Authentication is the privacy boundary. Once inside, Discord already
        // exposes the same member identity and rank to everyone in the server.
        return true;
    }

    public function updateProfile(User $viewer, User $member): bool
    {
        return $viewer->is($member);
    }
}
