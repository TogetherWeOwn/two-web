<?php

namespace App\Enums;

enum JoinOutcome: string
{
    case Added = 'added';
    case AlreadyMember = 'already_member';
    case Error = 'error';
    case Denied = 'denied';
    case Degraded = 'degraded';
}
