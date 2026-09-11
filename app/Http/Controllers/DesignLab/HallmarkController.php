<?php

namespace App\Http\Controllers\DesignLab;

use App\Models\FeaturedContent;
use App\Support\Counts\CountsSource;
use App\Support\Home\HomePageContent;
use Illuminate\View\View;

/**
 * The Hallmark homepage concept — isolated from the production route.
 *
 * It intentionally consumes the same content and live data as HomeController.
 * The route can therefore be reviewed as a visual alternative without gaining a
 * second set of claims, counts, ranks or moderator-published announcements.
 */
final class HallmarkController
{
    public function __invoke(CountsSource $counts): View
    {
        return view('design-lab.hallmark', [
            'content' => HomePageContent::lobbyLedger(),
            'counts' => $counts->liveCounts(),
            'ranks' => $counts->ranks(),
            'featured' => FeaturedContent::query()->currentlyVisible()->get(),
        ]);
    }
}
