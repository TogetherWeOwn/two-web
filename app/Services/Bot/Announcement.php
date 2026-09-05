<?php

namespace App\Services\Bot;

use App\Services\Bot\Exceptions\InvalidActionRequestException;

/**
 * One `announcement.post` call, validated at construction.
 *
 * **`channelKey` is a key, and its map starts empty.** Unlike the role map,
 * which inherits the self-assignable roles, there is no safe set of channels to
 * default to and no way to guess which channel is "announcements" — so a bot
 * with no `TWO_INTERNAL_CHANNEL_KEYS` refuses every post with
 * `action_not_allowed`. That is the correct answer rather than a gap: naming a
 * channel is a deliberate act by whoever runs the bot, and it means a bug on the
 * website cannot address an arbitrary channel.
 *
 * **The body cannot ping anybody.** The bot posts every announcement with
 * `allowed_mentions: { parse: [] }`, so an `@everyone` in here appears in the
 * message exactly as typed and notifies nobody. That is deliberate and it is not
 * a bug to report — an announcement is written by whoever has that form on the
 * website, and that form does not get to alert a live server.
 *
 * This action *needs an idempotency key* on every call: a repeat without one
 * posts a second message.
 */
final readonly class Announcement
{
    /** Discord's own ceiling, enforced here so the error names the field. */
    private const MAX_BODY = 2000;

    public function __construct(
        public string $channelKey,
        public string $body,
    ) {
        $this->validate();
    }

    /** @return array<string, string> */
    public function toPayload(): array
    {
        return [
            'action' => 'announcement.post',
            'channel_key' => $this->channelKey,
            'body' => $this->body,
        ];
    }

    private function validate(): void
    {
        if (trim($this->channelKey) === '') {
            throw new InvalidActionRequestException(
                'An announcement.post needs a channel_key. It is a key from the bot channel map, not a Discord channel id.'
            );
        }

        if (trim($this->body) === '') {
            throw new InvalidActionRequestException('An announcement.post needs a body.');
        }

        // Characters, not bytes — Discord counts characters, and the difference
        // is whether a 2000-character announcement with an accent in it posts.
        if (mb_strlen($this->body) > self::MAX_BODY) {
            throw new InvalidActionRequestException(
                'An announcement body is at most '.self::MAX_BODY.' characters; this one is '.mb_strlen($this->body).'.'
            );
        }
    }
}
