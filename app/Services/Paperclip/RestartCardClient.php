<?php

namespace App\Services\Paperclip;

use App\Services\Paperclip\Exceptions\PaperclipNotConfiguredException;
use App\Services\Paperclip\Exceptions\PaperclipTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place in this codebase that can write to the Paperclip board.
 *
 * When a *cold* bot setting is saved in the admin panel the change does nothing
 * until the bot process restarts, so the ADR (TOG-3093) requires an operator-
 * labelled card carrying the exact restart command and its rollback. two-web
 * files that card itself, from the server, by calling the control-plane REST API
 * — not the browser, not through the bot. The whole decision record is
 * docs/cold-setting-restart-cards.md.
 *
 * The trust model is InternalActionClient's, reused: exactly one class holds the
 * board-write token and constructs the request, so there is exactly one place to
 * leak the credential or to let caller text into a card. It is `final readonly`
 * and built once from config in AppServiceProvider.
 *
 * ## Fail closed
 *
 * A board-write token is a real credential and today none is provisioned. Every
 * config value is passed in, including the missing ones; file() refuses to send
 * unless all five are present, and a missing one is a PaperclipNotConfigured
 * Exception (terminal). The settings-save action turns that into a rejected
 * save, so an unprovisioned environment keeps cold settings read-only rather
 * than silently accepting a change no card was filed for.
 *
 * ## No arbitrary content
 *
 * file() transports a RestartCard, whose title and body ColdSetting built from
 * constants and one validated setting value. This class adds the operator label
 * and assignee from server config and sends nothing from any caller string.
 *
 * ## What is not here
 *
 * Retry and backoff. This makes one attempt and reports what happened; the save
 * action owns whether to retry, and it reuses the same idempotency key when it
 * does, so a retry collapses onto one card instead of filing a second.
 */
final readonly class RestartCardClient
{
    public function __construct(
        private ?string $url,
        private ?string $token,
        private ?string $companyId,
        private ?string $operatorLabelId,
        private ?string $operatorAssigneeUserId,
        private int $timeoutSeconds,
    ) {}

    /**
     * Throw unless every value needed to file a card is present.
     *
     * For a caller that needs to know *before* it starts — e.g. to decide
     * whether the cold master switch is editable at all this request. Every
     * file() checks for itself, so nothing is obliged to call this first, and a
     * caller that checks here and then sends has two chances to disagree about
     * what "configured" means.
     *
     * @throws PaperclipNotConfiguredException
     */
    public function assertConfigured(): void
    {
        $this->endpoint();
    }

    /**
     * File one operator restart card, and return the ids it was given.
     *
     * @param  string  $idempotencyKey  Owned by the caller (the save action) and
     *                                  identical across every attempt at the same
     *                                  save, so a double-submit collapses onto one
     *                                  card. Mint a fresh one per attempt and a
     *                                  retry files a second card.
     *
     * @throws PaperclipNotConfiguredException when any config value is missing
     * @throws PaperclipTransportException when the control-plane cannot be
     *                                     reached or does not answer with an issue
     */
    public function file(RestartCard $card, string $idempotencyKey): RestartCardResult
    {
        $url = $this->endpoint();

        $payload = [
            'title' => $card->title,
            'description' => $card->body,
            'labelIds' => [$this->operatorLabelId],
            'assigneeUserId' => $this->operatorAssigneeUserId,
            'idempotencyKey' => $idempotencyKey,
        ];

        try {
            $response = Http::withToken((string) $this->token)
                ->timeout($this->timeoutSeconds)
                ->asJson()
                ->acceptJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            $this->logUndelivered($idempotencyKey, $e);

            throw PaperclipTransportException::unreachable($url);
        }

        return $this->interpret($response, $idempotencyKey);
    }

    /**
     * The create-issue response, turned into a result.
     *
     * @throws PaperclipTransportException
     */
    private function interpret(Response $response, string $idempotencyKey): RestartCardResult
    {
        $status = $response->status();

        if (! $response->successful()) {
            // Never the body: a control-plane error page can echo the request,
            // and the request carried the card we are trying to keep out of logs.
            throw PaperclipTransportException::unreadable('a non-2xx status', $status);
        }

        $body = $response->json();
        $id = is_array($body) ? ($body['id'] ?? null) : null;
        $identifier = is_array($body) ? ($body['identifier'] ?? null) : null;

        // The identifier is the point of the call: without it the save action
        // cannot tell the moderator which card was filed, so a success without
        // one is not a success we can return.
        if (! is_string($id) || $id === '' || ! is_string($identifier) || $identifier === '') {
            throw PaperclipTransportException::unreadable('a created issue with no id or identifier', $status);
        }

        Log::info('Filed operator restart card.', [
            'issue_id' => $id,
            'identifier' => $identifier,
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
        ]);

        return new RestartCardResult(issueId: $id, identifier: $identifier);
    }

    /**
     * The create-issue URL, once we know we are configured to write at all.
     *
     * Checked before anything is built or sent. A blank token would otherwise
     * produce a perfectly well-formed request the control-plane rejects as
     * unauthorized — indistinguishable from a revoked token, and the hardest
     * thing to diagnose from the far end.
     *
     * @throws PaperclipNotConfiguredException
     */
    private function endpoint(): string
    {
        if (($this->url ?? '') === '') {
            throw PaperclipNotConfiguredException::missing('PAPERCLIP_API_URL');
        }

        if (($this->token ?? '') === '') {
            throw PaperclipNotConfiguredException::missing('PAPERCLIP_API_TOKEN');
        }

        if (($this->companyId ?? '') === '') {
            throw PaperclipNotConfiguredException::missing('PAPERCLIP_COMPANY_ID');
        }

        if (($this->operatorLabelId ?? '') === '') {
            throw PaperclipNotConfiguredException::missing('PAPERCLIP_OPERATOR_LABEL_ID');
        }

        if (($this->operatorAssigneeUserId ?? '') === '') {
            throw PaperclipNotConfiguredException::missing('PAPERCLIP_OPERATOR_ASSIGNEE_USER_ID');
        }

        return rtrim((string) $this->url, '/')."/api/companies/{$this->companyId}/issues";
    }

    private function logUndelivered(string $idempotencyKey, Throwable $e): void
    {
        $message = $e->getMessage();

        // The token can be quoted in a client exception message; the card body
        // never is (it is not a secret), so only the token needs scrubbing here.
        if (($this->token ?? '') !== '') {
            $message = str_replace((string) $this->token, '[redacted]', $message);
        }

        Log::warning('Operator restart card could not be delivered.', [
            'idempotency_key' => $idempotencyKey,
            'exception' => $message,
        ]);
    }
}
