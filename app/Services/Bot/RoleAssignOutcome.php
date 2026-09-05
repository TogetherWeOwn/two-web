<?php

namespace App\Services\Bot;

/**
 * What the bot did with a `role.assign`.
 *
 * Both are successes, and the distinction is the reason this action needs no
 * idempotency key: Discord itself makes the repeat harmless, so asking twice is
 * safe and the second answer simply says so.
 */
enum RoleAssignOutcome: string
{
    case Assigned = 'assigned';
    case AlreadyHeld = 'already_held';
}
