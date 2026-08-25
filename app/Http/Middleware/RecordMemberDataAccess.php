<?php

namespace App\Http\Middleware;

use App\Support\MemberDataAccess\AccessRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Records the member data a request read, before that request is allowed to
 * answer.
 *
 * Applied as `member-access-log` to the admin panel's middleware stack, where it
 * covers every screen the panel has now and every screen it grows later:
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
            $recorder->flush($request);
        } catch (Throwable $e) {
            // Loud, and without the subjects in it: this line goes to the ordinary
            // application log, which does not have the access log's retention or
            // its handling rules.
            Log::critical('Member data access could not be recorded; refusing to serve the read.', [
                'route' => $request->route()?->getName(),
                'exception' => $e->getMessage(),
            ]);

            if ((bool) config('member_access_log.enforce')) {
                throw new ServiceUnavailableHttpException(null, 'Member data is temporarily unavailable.');
            }
        }

        return $response;
    }
}
