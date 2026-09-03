<?php

namespace App\Services\Bot\Exceptions;

use InvalidArgumentException;

/**
 * We were asked to send the bot something it has already said it will refuse.
 *
 * Every rule enforced through this exception is one the bot enforces too and
 * answers with `malformed`. Checking here as well is not belt and braces: a
 * `malformed` is non-retryable, so a queued job that hits one has burned an
 * attempt and produced an error message about a field name, while this happens
 * before the operation is ever queued and can name the value.
 *
 * An InvalidArgumentException rather than a BotException, because this is our
 * bug or our bad data — nothing about the bot is involved.
 */
final class InvalidActionRequestException extends InvalidArgumentException {}
