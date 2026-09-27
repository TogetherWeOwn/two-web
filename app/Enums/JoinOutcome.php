<?php

namespace App\Enums;

enum JoinOutcome: string
{
    case Added = 'added';
    case AlreadyMember = 'already_member';

    /**
     * The join was attempted but could not complete because Discord (OAuth
     * token exchange) or the bot contract did not answer. Logged, never
     * stored on a user row — there is no member to attach it to.
     */
    case Error = 'error';
}
