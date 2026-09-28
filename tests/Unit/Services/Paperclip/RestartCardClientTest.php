<?php

use App\Services\Paperclip\ColdSetting;
use App\Services\Paperclip\Exceptions\PaperclipNotConfiguredException;
use App\Services\Paperclip\Exceptions\PaperclipTransportException;
use App\Services\Paperclip\RestartCard;
use App\Services\Paperclip\RestartCardClient;
use App\Services\Paperclip\RestartCardResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

const PAPERCLIP_URL = 'https://control.example.test';
const PAPERCLIP_TOKEN = 'pk-test-token-at-least-32-characters-long';
const PAPERCLIP_COMPANY = 'company-abc';
const PAPERCLIP_LABEL = 'label-operator-uuid';
const PAPERCLIP_PARENT = 'parent-issue-uuid';
const PAPERCLIP_ASSIGNEE = 'agent-devops-uuid';
const PAPERCLIP_ENDPOINT = 'https://control.example.test/api/companies/company-abc/issues';
const IDEMPOTENCY_KEY = 'cold-setting:TWO_AUTOMOD:true:rev-7';

function restartClient(
    ?string $url = PAPERCLIP_URL,
    ?string $token = PAPERCLIP_TOKEN,
    ?string $companyId = PAPERCLIP_COMPANY,
    ?string $labelId = PAPERCLIP_LABEL,
    ?string $parent = PAPERCLIP_PARENT,
    ?string $assignee = PAPERCLIP_ASSIGNEE,
    ?string $environment = 'staging',
): RestartCardClient {
    return new RestartCardClient($url, $token, $companyId, $labelId, $parent, $assignee, $environment, 5);
}

function sampleCard(): RestartCard
{
    return new RestartCard(
        title: 'Operator: restart TWO bot to apply TWO_AUTOMOD=true',
        body: "A cold bot setting was changed.\n\n```\nrestart me\n```",
    );
}

/** The control-plane's created-issue envelope (packages/shared issue create). */
function createdIssue(string $id = 'issue-uuid-1', string $identifier = 'TOG-9999'): array
{
    return ['id' => $id, 'identifier' => $identifier, 'status' => 'todo'];
}

// ---------------------------------------------------------------------------
// The happy path, and the bytes on the wire.
// ---------------------------------------------------------------------------

it('posts to the company create-issue path with a bearer token', function () {
    Http::fake([PAPERCLIP_ENDPOINT => Http::response(createdIssue(), 201)]);

    restartClient()->file(sampleCard(), IDEMPOTENCY_KEY);

    Http::assertSent(fn (Request $r) => $r->url() === PAPERCLIP_ENDPOINT
        && $r->method() === 'POST'
        && $r->header('Authorization')[0] === 'Bearer '.PAPERCLIP_TOKEN);
});

it('tolerates a trailing slash on the configured url', function () {
    Http::fake([PAPERCLIP_ENDPOINT => Http::response(createdIssue(), 201)]);

    restartClient(url: PAPERCLIP_URL.'/')->file(sampleCard(), IDEMPOTENCY_KEY);

    Http::assertSent(fn (Request $r) => $r->url() === PAPERCLIP_ENDPOINT);
});

it('sends the card as a child of the parent, assigned to the restart agent, never to a user', function () {
    Http::fake([PAPERCLIP_ENDPOINT => Http::response(createdIssue(), 201)]);

    $card = sampleCard();
    restartClient()->file($card, IDEMPOTENCY_KEY);

    Http::assertSent(fn (Request $r) => $r['title'] === $card->title
        && $r['description'] === $card->body
        && $r['parentId'] === PAPERCLIP_PARENT
        && $r['labelIds'] === [PAPERCLIP_LABEL]
        && $r['assigneeAgentId'] === PAPERCLIP_ASSIGNEE
        && $r['idempotencyKey'] === IDEMPOTENCY_KEY
        // A task_bridge key is refused outright for a user assignee.
        && ! array_key_exists('assigneeUserId', $r->data()));
});

it('returns the ids the control-plane assigned the card', function () {
    Http::fake([PAPERCLIP_ENDPOINT => Http::response(createdIssue('the-id', 'TOG-4242'), 201)]);

    $result = restartClient()->file(sampleCard(), IDEMPOTENCY_KEY);

    expect($result)->toBeInstanceOf(RestartCardResult::class)
        ->and($result->issueId)->toBe('the-id')
        ->and($result->identifier)->toBe('TOG-4242');
});

// ---------------------------------------------------------------------------
// Fail closed: any missing config value refuses to send at all.
// ---------------------------------------------------------------------------

it('refuses to send and names the variable when a config value is empty', function (string $variable, RestartCardClient $client) {
    Http::fake();

    try {
        $client->file(sampleCard(), IDEMPOTENCY_KEY);
        test()->fail('Expected a PaperclipNotConfiguredException.');
    } catch (PaperclipNotConfiguredException $e) {
        // Names the missing variable, quotes no value.
        expect($e->getMessage())->toContain($variable)
            ->and($e->getMessage())->not->toContain(PAPERCLIP_TOKEN);
    }

    Http::assertNothingSent();
})->with([
    'url' => fn () => ['PAPERCLIP_API_URL', restartClient(url: '')],
    'token' => fn () => ['PAPERCLIP_API_TOKEN', restartClient(token: '')],
    'company id' => fn () => ['PAPERCLIP_COMPANY_ID', restartClient(companyId: '')],
    'label id' => fn () => ['PAPERCLIP_OPERATOR_LABEL_ID', restartClient(labelId: '')],
    'parent' => fn () => ['PAPERCLIP_PARENT_ISSUE_ID', restartClient(parent: '')],
    'assignee' => fn () => ['PAPERCLIP_RESTART_ASSIGNEE_AGENT_ID', restartClient(assignee: '')],
    'bot environment' => fn () => ['PAPERCLIP_RESTART_BOT_ENVIRONMENT', restartClient(environment: '')],
    'unknown bot environment' => fn () => ['PAPERCLIP_RESTART_BOT_ENVIRONMENT', restartClient(environment: 'prod')],
]);

it('builds the card for the configured bot environment', function (string $environment, string $uuid) {
    $card = restartClient(environment: $environment)->cardFor(ColdSetting::TWO_AUTOMOD, 'false');

    expect($card->title)->toContain("({$environment})")
        ->and($card->body)->toContain("/applications/{$uuid}/restart");
})->with([
    'staging' => ['staging', 'uy4d9ndeygjcem6lgayhxgub'],
    'production' => ['production', 'cangagerae31txrk2vfvzzyq'],
]);

it('refuses to build a card when the bot environment is not configured', function () {
    expect(fn () => restartClient(environment: null)->cardFor(ColdSetting::TWO_AUTOMOD, 'true'))
        ->toThrow(PaperclipNotConfiguredException::class);
});

it('treats an absent (null) config value the same as an empty one', function () {
    Http::fake();

    expect(fn () => restartClient(token: null)->file(sampleCard(), IDEMPOTENCY_KEY))
        ->toThrow(PaperclipNotConfiguredException::class);

    Http::assertNothingSent();
});

it('exposes assertConfigured for a caller that needs to know before it starts', function () {
    expect(fn () => restartClient(token: '')->assertConfigured())
        ->toThrow(PaperclipNotConfiguredException::class);

    restartClient()->assertConfigured();
})->throwsNoExceptions();

it('keeps not-configured and transport failures as separate types', function () {
    expect(is_a(PaperclipNotConfiguredException::class, PaperclipTransportException::class, true))->toBeFalse()
        ->and(is_a(PaperclipTransportException::class, PaperclipNotConfiguredException::class, true))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The control-plane did not answer, or answered something unusable.
// ---------------------------------------------------------------------------

it('throws a transport exception when the control-plane cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => restartClient()->file(sampleCard(), IDEMPOTENCY_KEY))
        ->toThrow(PaperclipTransportException::class);
});

it('throws a transport exception on a non-2xx status', function () {
    Http::fake([PAPERCLIP_ENDPOINT => Http::response('<html>500</html>', 500)]);

    expect(fn () => restartClient()->file(sampleCard(), IDEMPOTENCY_KEY))
        ->toThrow(PaperclipTransportException::class);
});

it('throws a transport exception on a 2xx with no issue id or identifier', function () {
    Http::fake([PAPERCLIP_ENDPOINT => Http::response(['status' => 'todo'], 201)]);

    expect(fn () => restartClient()->file(sampleCard(), IDEMPOTENCY_KEY))
        ->toThrow(PaperclipTransportException::class);
});

// ---------------------------------------------------------------------------
// Logging: the identifier in, the token out.
// ---------------------------------------------------------------------------

it('logs the filed identifier on success', function () {
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('testing', [$handler])));

    Http::fake([PAPERCLIP_ENDPOINT => Http::response(createdIssue('id-1', 'TOG-7777'), 201)]);

    restartClient()->file(sampleCard(), IDEMPOTENCY_KEY);

    $logged = json_encode(array_map(fn ($r) => [$r->message, $r->context], $handler->getRecords()));

    expect($logged)->toContain('TOG-7777')
        ->and($logged)->toContain(IDEMPOTENCY_KEY);
});

it('never logs the token, even when a connection error quotes it', function () {
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('testing', [$handler])));

    // A client exception can quote the outgoing request, token and all.
    Http::fake(fn () => throw new ConnectionException('POST failed with Bearer '.PAPERCLIP_TOKEN));

    try {
        restartClient()->file(sampleCard(), IDEMPOTENCY_KEY);
    } catch (PaperclipTransportException) {
        // expected
    }

    $logged = json_encode(array_map(fn ($r) => [$r->message, $r->context], $handler->getRecords()));

    expect($handler->getRecords())->not->toBeEmpty()
        ->and($logged)->not->toContain(PAPERCLIP_TOKEN)
        ->and($logged)->toContain('[redacted]');
});

// ---------------------------------------------------------------------------
// The container wiring. Config in one place, and the class resolvable.
// ---------------------------------------------------------------------------

it('resolves from the container against the paperclip config', function () {
    config()->set('services.paperclip.url', 'https://configured.example.test');
    config()->set('services.paperclip.token', PAPERCLIP_TOKEN);
    config()->set('services.paperclip.company_id', 'configured-company');
    config()->set('services.paperclip.operator_label_id', 'configured-label');
    config()->set('services.paperclip.parent_issue_id', 'configured-parent');
    config()->set('services.paperclip.restart_assignee_agent_id', 'configured-agent');
    config()->set('services.paperclip.bot_environment', 'staging');

    $endpoint = 'https://configured.example.test/api/companies/configured-company/issues';
    Http::fake([$endpoint => Http::response(createdIssue(), 201)]);

    app(RestartCardClient::class)->file(sampleCard(), IDEMPOTENCY_KEY);

    Http::assertSent(fn (Request $r) => $r->url() === $endpoint
        && $r['labelIds'] === ['configured-label']
        && $r['parentId'] === 'configured-parent'
        && $r['assigneeAgentId'] === 'configured-agent');
});
