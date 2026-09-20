<?php

namespace App\Services\Paperclip\Exceptions;

use App\Services\Paperclip\ColdSetting;

/**
 * The exact host restart/rollback command for a cold setting is not pinned yet.
 *
 * Host-ops knowledge is not fabricated in this repo (docs/cold-setting-restart-
 * cards.md, Decision 4): the canonical two-bot Coolify restart command is pinned
 * by the operator provisioning card (TOG-3573) and only then encoded as the
 * matching ColdSetting constant. Until it is, ColdSetting::restartCard() throws
 * this rather than filing a card that tells a human to run a guessed command —
 * one more way the filer fails closed.
 */
final class RestartCommandNotPinnedException extends PaperclipException
{
    public static function for(ColdSetting $setting): self
    {
        return new self(
            "The restart command for cold setting {$setting->value} is not pinned yet; ".
            'it must be filled in from the operator provisioning card (TOG-3573) before a card can be filed.'
        );
    }
}
