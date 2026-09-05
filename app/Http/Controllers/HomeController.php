<?php

namespace App\Http\Controllers;

use App\Models\FeaturedContent;
use Illuminate\Contracts\View\View;

/**
 * The landing page.
 *
 * This replaced a bare `Route::view('/', 'home')`, and the reason is the whole of
 * TOG-54: the featured-content admin panel shipped with a model, a resource, a
 * policy and a `currentlyVisible()` scope that nothing on the site ever called.
 * Moderators could save rows all day and no visitor would ever see one. A CRUD
 * screen with no consumer is not a feature.
 *
 * The query lives here rather than in the Blade template so the page has one
 * documented reason to touch the database, and so the ordering contract is
 * testable without rendering HTML.
 */
class HomeController
{
    public function __invoke(): View
    {
        return view('home', [
            // `currentlyVisible()` is the only question the public site asks of
            // this table: published, inside its window, in the order moderators
            // arranged. Keeping the filter in the model scope means the admin
            // preview and the landing page cannot drift apart.
            'featured' => FeaturedContent::query()->currentlyVisible()->get(),
        ]);
    }
}
