<?php

namespace App\Services\Paperclip\Exceptions;

use RuntimeException;

/**
 * Something went wrong on the way to filing an operator restart card.
 *
 * Two subclasses, and the type is the whole point: PaperclipNotConfigured
 * Exception is terminal (we are not set up to write to the board and never will
 * be until a token is provisioned), while PaperclipTransportException is a
 * momentary "we asked and got nothing usable". The settings-save action treats
 * both as a rejected save, but a caller that wants to retry can tell them apart.
 */
abstract class PaperclipException extends RuntimeException {}
