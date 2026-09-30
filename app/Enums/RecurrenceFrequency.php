<?php

namespace App\Enums;

/**
 * How a recurring series repeats. Weekly only: the Sunday Squad meets every
 * seven days, and one frequency is all the card asks for. A second case here
 * must also teach RecurrenceSchedule a new step, so the enum growing is the
 * signal that the date math grew too.
 */
enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
}
