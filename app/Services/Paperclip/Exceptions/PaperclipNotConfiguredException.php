<?php

namespace App\Services\Paperclip\Exceptions;

/**
 * We are not set up to write to the Paperclip board at all.
 *
 * Deliberately its own type and not a transport error. It is what makes the
 * filer fail *closed*: the settings-save action turns this into a rejected save,
 * so an environment with no token provisioned keeps cold settings read-only
 * rather than accepting a change no operator card was filed for.
 *
 * The message names the environment variable and never quotes a value — the
 * point of this class is that the value is missing.
 */
final class PaperclipNotConfiguredException extends PaperclipException
{
    public static function missing(string $variable): self
    {
        return new self("The Paperclip restart-card filer is not configured: {$variable} is empty.");
    }
}
