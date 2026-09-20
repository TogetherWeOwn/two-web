<?php

namespace App\Services\Bot;

/** What `settings.set` did to the stored override. */
enum SettingWriteOutcome: string
{
    case Saved = 'saved';
    case Unset = 'unset';
}
