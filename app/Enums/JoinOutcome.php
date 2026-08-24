<?php

namespace App\Enums;

/**
 * The two ways `guild.add_member` succeeds.
 *
 * Both are successes and they are different things to show a person, which is
 * why this is an enum and not a boolean: 201 means we just put them in the
 * server, 204 means they were already there. Neither is an error, and telling
 * an existing member "something went wrong" would be a lie.
 *
 * The strings are the bot's wire values from docs/INTERNAL_ACTIONS.md §3. They
 * are also the translation keys under `join.result.*`, so a new outcome cannot
 * reach a member without somebody writing them a sentence.
 */
enum JoinOutcome: string
{
    case Added = 'added';
    case AlreadyMember = 'already_member';
}
