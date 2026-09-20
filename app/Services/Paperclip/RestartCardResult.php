<?php

namespace App\Services\Paperclip;

/**
 * A filed operator restart card: the ids the control-plane assigned it.
 *
 * The identifier (e.g. `TOG-9999`) is the point of the call — it is how the save
 * action tells the moderator which card was filed, and how a log line joins to
 * the board.
 */
final readonly class RestartCardResult
{
    public function __construct(
        public string $issueId,
        public string $identifier,
    ) {}
}
