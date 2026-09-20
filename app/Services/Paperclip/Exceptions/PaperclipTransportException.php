<?php

namespace App\Services\Paperclip\Exceptions;

/**
 * We asked the control-plane, and got back nothing we can act on.
 *
 * Either it could not be reached, or it answered something that is not a created
 * issue — a reverse-proxy error page, a half-deployed control-plane, a truncated
 * body. Distinct from PaperclipNotConfiguredException (terminal): this one is the
 * kind of failure a caller could reasonably retry, though the save action treats
 * any failure to file the card as a rejected save.
 */
final class PaperclipTransportException extends PaperclipException
{
    public static function unreachable(string $url): self
    {
        return new self("The Paperclip control-plane at {$url} could not be reached.");
    }

    public static function unreadable(string $why, int $status): self
    {
        return new self("The Paperclip control-plane answered {$status} with something that is not a created issue: {$why}.");
    }
}
