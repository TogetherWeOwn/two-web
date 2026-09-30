<?php

use App\Services\Bot\Announcement;
use App\Services\Bot\AnnouncementResult;
use App\Services\Bot\Exceptions\InvalidActionRequestException;
use App\Services\Bot\InternalActionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function jsonEncodingClient(): InternalActionClient
{
    return new InternalActionClient(
        'http://bot.internal:3001',
        'two-web-test-secret-at-least-32-characters',
        'web-test',
        5,
    );
}

it('rejects invalid UTF-8 in an announcement as a local JSON failure without transport', function () {
    Http::fake();
    Http::preventStrayRequests();

    // The DTO accepts the nonblank, short body; encoding must reject its bytes.
    $announcement = new Announcement('qa-throwaway', "hello \xC3\x28");

    try {
        jsonEncodingClient()->postAnnouncement($announcement, InternalActionClient::newIdempotencyKey());

        test()->fail('An invalid UTF-8 announcement must fail before transport.');
    } catch (InvalidActionRequestException $exception) {
        expect($exception->getMessage())->toBe('The action payload could not be encoded as JSON.')
            ->and($exception->getPrevious())->toBeInstanceOf(JsonException::class)
            ->and($exception->getPrevious()->getCode())->toBe(JSON_ERROR_UTF8);
    }

    Http::assertNothingSent();
});

it('transports a valid Unicode announcement body unchanged', function () {
    Http::fake([
        'http://bot.internal:3001/internal/actions' => Http::response([
            'ok' => true,
            'result' => ['outcome' => 'posted', 'message_id' => '1122334455667788'],
            'request_id' => '01JANN0123456789',
        ]),
    ]);
    Http::preventStrayRequests();

    $body = 'Café — 🎮 https://example.test/é';
    $result = jsonEncodingClient()->postAnnouncement(
        new Announcement('qa-throwaway', $body),
        InternalActionClient::newIdempotencyKey(),
    );

    expect($result)->toBeInstanceOf(AnnouncementResult::class)
        ->and($result->messageId)->toBe('1122334455667788');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->data() === [
        'action' => 'announcement.post',
        'channel_key' => 'qa-throwaway',
        'body' => $body,
    ]);
});
