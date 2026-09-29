<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\FeaturedContent;
use App\Support\Counts\CountsSource;
use App\Support\Home\HomePageContent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;

/**
 * The landing page — the top of the join funnel (TOG-48).
 *
 * A controller rather than the `Route::view` this replaced, because the page
 * now reads the bot's counts. Everything it hands the view is already decided:
 * the view asks "is there a member count" and never "how old is this timestamp".
 *
 * It also reads the featured rows, which is the whole of TOG-54: the
 * featured-content admin panel shipped with a model, a resource, a policy and a
 * `currentlyVisible()` scope that nothing on the site ever called. Moderators
 * could save rows all day and no visitor would ever see one. A CRUD screen with
 * no consumer is not a feature.
 *
 * Both queries live here rather than in the Blade template so the page has one
 * documented reason to touch the database, and so the ordering contract is
 * testable without rendering HTML.
 *
 * TOG-6927 adds a third: the next upcoming events for the home teaser. The
 * scope is the public one — published and not yet ended, soonest first, capped
 * at three — the same guest rule the RSS feed enforces. Drafts stay out for
 * everybody: a draft is unannounced by definition, and moderator preview lives
 * on /events and /admin. Server-rendered Blade, never a Livewire component:
 * CriticalPathTest pins home as Livewire-free so the runtime never enters the
 * landing page's critical path, and EventQueryCountTest bounds the page's
 * queries, so this stays exactly one query.
 */
final class HomeController
{
    public function __invoke(CountsSource $counts): View
    {
        return $this->render('home', $counts);
    }

    public function taste(CountsSource $counts): View
    {
        // The route is not registered in production, but a cached or manually
        // registered route must still fail closed — same pattern as the
        // staging QA login.
        abort_unless(! app()->environment('production'), 404);

        return $this->render('design-lab.taste', $counts);
    }

    /**
     * The next published events for the home teaser, soonest first.
     *
     * One query, capped at three: the home teaser is a signpost, not the
     * calendar — the full list lives on /events. `ends_at >= now` is the
     * calendar's definition of upcoming (an event happening right now still
     * counts), shared with EventRssController so the two cannot drift apart.
     *
     * @return Collection<int, Event>
     */
    private static function upcomingEvents(): Collection
    {
        return Event::query()
            ->where('status', EventStatus::Published->value)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(3)
            ->get();
    }

    /** @param view-string $view */
    private function render(string $view, CountsSource $counts): View
    {
        return view($view, [
            'content' => HomePageContent::lobbyLedger(),
            'counts' => $counts->liveCounts(),
            'ranks' => $counts->ranks(),
            // `currentlyVisible()` is the only question the public site asks of
            // this table: published, inside its window, in the order moderators
            // arranged. Keeping the filter in the model scope means the admin
            // preview and the landing page cannot drift apart.
            'featured' => FeaturedContent::query()->currentlyVisible()->get(),
            'upcomingEvents' => self::upcomingEvents(),
        ]);
    }
}
