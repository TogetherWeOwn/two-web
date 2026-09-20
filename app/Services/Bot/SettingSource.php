<?php

namespace App\Services\Bot;

/** Where a `settings.get` value came from. `unset` means environment fallback. */
enum SettingSource: string
{
    case Store = 'store';
    case Unset = 'unset';
}
