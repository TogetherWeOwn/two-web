<?php

namespace App\Services\Bot\Exceptions;

use RuntimeException;

/**
 * Something went wrong on the way to the bot, rather than at the bot.
 *
 * The bot saying no is an InternalActionFailure, not an exception. These two
 * subclasses are the cases where there is no answer to read: we are not
 * configured to ask, or we asked and got nothing usable back.
 */
abstract class BotException extends RuntimeException {}
