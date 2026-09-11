<?php

namespace App\Http\Controllers;

use App\Models\FeaturedContent;
use App\Support\Counts\CountsSource;
use App\Support\Home\HomePageContent;
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
 */
final class HomeController
{
    public function __invoke(CountsSource $counts): View
    {
        return $this->render('home', $counts);
    }

    public function taste(CountsSource $counts): View
    {
        return $this->render('design-lab.taste', $counts);
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
        ]);
    }
}
