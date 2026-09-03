<?php

namespace App\Http\Controllers;

use App\Support\Counts\CountsSource;
use Illuminate\View\View;

/**
 * The landing page — the top of the join funnel (TOG-48).
 *
 * A controller rather than the `Route::view` this replaced, because the page
 * now reads the bot's counts. Everything it hands the view is already decided:
 * the view asks "is there a member count" and never "how old is this timestamp".
 */
final class HomeController
{
    public function __invoke(CountsSource $counts): View
    {
        return view('home', [
            'counts' => $counts->liveCounts(),
            'ranks' => $counts->ranks(),
        ]);
    }
}
