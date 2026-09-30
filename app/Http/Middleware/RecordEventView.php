<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\EventViewCount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts human views of the shareable event page (TOG-8408).
 *
 * The route binds the event before this runs, so the controller's page is the
 * thing being counted — drafts 403 and cancelled 410s never reach the
 * increment, because the authorization and the Gone response live downstream
 * and this only writes after the response comes back successful. Guests count:
 * a signed-out visitor arriving from Discord is the audience this exists for.
 * Bot and crawler traffic is excluded on the User-Agent before anything is
 * recorded, so the moderator-visible total is people, not the index.
 *
 * One increment is an INSERT-or-ignore plus one atomic UPDATE. The first view
 * of the day races on the INSERT against the unique key on (event_id,
 * viewed_on): the loser ignores instead of raising, then both increment the
 * winner's row. Deliberately exception-free — catching a 23505 abort poisons
 * any surrounding transaction (every RefreshDatabase feature test runs in
 * one), while insertOrIgnore leaves it clean. The count never blocks the
 * page — a write failure is swallowed, because a view counter must not 500
 * the view.
 */
class RecordEventView
{
    /**
     * User-Agent fragments that mark the request as automated. Deliberately
     * substring, deliberately lowercase-compared: crawlers rotate their exact
     * tokens but keep the family name. `curl`, `wget` and the http libraries
     * are here too — a script fetching the page is not a person reading it.
     *
     * @var list<string>
     */
    private const BOT_FRAGMENTS = [
        'bot',
        'crawl',
        'spider',
        'slurp',
        'mediapartners-google',
        'baidu',
        'yandex',
        'sogou',
        'exabot',
        'facebot',
        'facebookexternalhit',
        'ia_archiver',
        'alexa',
        'pingdom',
        'preview',
        'discordbot',
        'twitterbot',
        'linkedinbot',
        'slackbot',
        'telegrambot',
        'whatsapp',
        'embedly',
        'quora link preview',
        'outbrain',
        'pinterest',
        'slack-imgproxy',
        'curl',
        'wget',
        'python-requests',
        'python-urllib',
        'go-http-client',
        'java/',
        'okhttp',
        'httpclient',
        'libwww-perl',
        'php/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $event = $request->route()?->parameter('event');

        if (! $event instanceof Event) {
            return $response;
        }

        if (self::isAutomated($request->userAgent())) {
            return $response;
        }

        $this->increment($event->getKey());

        return $response;
    }

    public static function isAutomated(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            // No User-Agent at all: a browser always sends one, a prober often
            // does not. Excluding the empty string keeps the count to traffic
            // that at least claims to be a browser.
            return true;
        }

        $agent = strtolower($userAgent);

        foreach (self::BOT_FRAGMENTS as $fragment) {
            if (str_contains($agent, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function increment(int|string $eventId): void
    {
        $today = today()->toDateString();

        try {
            // ON CONFLICT DO NOTHING on the (event_id, viewed_on) unique key:
            // the loser's write is a no-op, not an exception. A failed INSERT
            // would abort any surrounding transaction (see Feature tests built
            // on RefreshDatabase); a conflict that never raises cannot.
            EventViewCount::query()->insertOrIgnore([
                'event_id' => $eventId,
                'viewed_on' => $today,
                'views' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            EventViewCount::query()
                ->where('event_id', $eventId)
                ->where('viewed_on', $today)
                ->increment('views');
        } catch (Throwable) {
            // The page must still be served: a view counter never 500s the view.
        }
    }
}
