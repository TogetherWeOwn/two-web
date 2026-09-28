<?php

namespace App\Services\Paperclip;

/**
 * The fixed content of one operator restart card: a title and a body, and
 * nothing a caller typed.
 *
 * In production these are built only by ColdSetting::restartCard(), whose title
 * and body are constants plus one validated setting value. That is what keeps
 * moderator free-text off the board (docs/cold-setting-restart-cards.md,
 * Decision 4). This is a plain value object — the label and assignee are added by
 * RestartCardClient from server config, not carried here.
 */
final readonly class RestartCard
{
    public function __construct(
        public string $title,
        public string $body,
    ) {}
}
