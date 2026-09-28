<?php

namespace App\Enums;

enum RsvpStatus: string
{
    case Going = 'going';
    case Maybe = 'maybe';
    case NotGoing = 'not_going';

    /**
     * In line for a seat, not holding one. A full event refuses Going but
     * accepts this, so the member has somewhere to go besides a refusal.
     * Every `going_count` aggregate filters on Going, so a waitlisted row
     * changes no count and needs no migration — the column is a string.
     */
    case Waitlisted = 'waitlisted';
}
