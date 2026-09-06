<?php

namespace App\Enums;

enum JoinOutcome: string
{
    case Added = 'added';
    case AlreadyMember = 'already_member';
}
