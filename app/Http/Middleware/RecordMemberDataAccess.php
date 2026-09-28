<?php

namespace App\Http\Middleware;

use App\Models\Profile;
use App\Models\User;
use App\Support\MemberDataAccess\AccessRecorder;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Records the member data a request read, before that request is allowed to
 * answer.
 *
 * Applied as `member-access-log` to the authenticated member-profile routes and
 * the admin panel's middleware stack, including future screens on that stack:
 *
 *     ->middleware(['auth', 'can:access-admin', 'member-access-log'])
 *
 * The ordering matters and is the whole control: the log row is written *after*
 * the response is generated (so it knows which records were read) but *before*
 * the response is returned (so a read that could not be recorded is not served).
 * If the log is down, moderators cannot browse member data. That is the intended
 * behaviour and it is the reason the narrow-first moderator role on TOG-106 is
 * safe rather than merely optimistic — "we can narrow it later" is only true if
 * we can say who looked in the meantime.
 */
class RecordMemberDataAccess
{
    /**
     * @param  string  $resource  What kind of thing this route reads.
     * @param  string  $action  'view' for one record, 'list' for a page of them.
     */
    public function handle(Request $request, Closure $next, string $resource = 'member', string $action = 'view'): Response
    {
        $recorder = app(AccessRecorder::class);
        $recorder->arm($resource, $action);

        $response = $next($request);

        try {
            // Route-model binding can hydrate the member before this middleware
            // arms the listener. Include those models even when the member has no
            // Profile row to retrieve later. Do not count a denied/missing target
            // as a served read; the recorder still excludes the viewer and dedupes
            // models also observed while the response was generated.
            if ($response->getStatusCode() < 400) {
                foreach ($request->route()?->parameters() ?? [] as $parameter) {
                    if ($parameter instanceof User || $parameter instanceof Profile) {
                        $recorder->observe($parameter);
                    }
                }
            }

            $recorded = $recorder->flush($request);
        } catch (Throwable $e) {
            $this->refuse($request, 'Member data access could not be recorded; refusing to serve the read.', [
                'exception' => $e::class,
                'sqlstate' => $e instanceof QueryException ? (string) $e->getCode() : null,
            ]);

            return $response;
        }

        // Read, but not attributable to anyone: a profile row selected without
        // enough of itself to say which member it is about. Refused rather than
        // dropped, and refused even when other subjects *were* recorded, because
        // a row that names two of the three members whose data was served is a
        // worse answer to "who was looked at?" than no answer at all.
        if ($recorder->hasUnattributableRead()) {
            $this->refuse($request, 'Member data was read that could not be attributed to a member; refusing to serve the read.', []);

            return $response;
        }

        // A streamed or file response has not produced its body yet — that happens
        // at send(), after every middleware has returned — so the flush above ran
        // against whatever the controller hydrated before handing back the
        // callback, and for a bare `stream(fn () => ...)` that is nothing at all.
        // Recording nothing is then indistinguishable from a page that showed no
        // member data, and the difference is a CSV of the membership leaving with
        // no row against it.
        //
        // We cannot record what has not happened yet, and this is the last moment
        // we could refuse, so we refuse. A screen that reads its members through
        // Eloquent before returning the stream — or that calls note() — has
        // already been recorded by the time we get here and is served normally.
        if (! $recorded && $this->bodyComesLater($response)) {
            $this->refuse($request, 'Member data access cannot be recorded for a response whose body is produced after the middleware; refusing to serve the read.', [
                'response' => $response::class,
            ]);
        }

        return $response;
    }

    private function bodyComesLater(Response $response): bool
    {
        return $response instanceof StreamedResponse || $response instanceof BinaryFileResponse;
    }

    /**
     * Loud, and genuinely without the subjects in it.
     *
     * This line goes to the ordinary application log, which has neither the access
     * log's retention window nor its handling rules — so it carries the exception
     * class and the SQLSTATE and never the message. A QueryException's message is
     * the failed INSERT with its bindings substituted in: the viewer's snowflake
     * and every subject id. That is the spill this control exists to prevent, and
     * it would happen only on the failure path, which is the moment nobody is
     * reading carefully.
     *
     * @param  array<string, string|null>  $context
     */
    private function refuse(Request $request, string $message, array $context): void
    {
        Log::critical($message, ['route' => $request->route()?->getName()] + $context);

        if ((bool) config('member_access_log.enforce')) {
            throw new ServiceUnavailableHttpException(null, 'Member data is temporarily unavailable.');
        }
    }
}
