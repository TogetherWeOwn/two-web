<?php

use App\Http\Controllers\Auth\DiscordLoginController;
use App\Http\Controllers\JoinController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// One-click join (TOG-80). A separate journey from login on purpose: it asks for
// `guilds.join`, which login must never ask for.
//
// The callback path is fixed at /join/callback in every environment and is
// registered as a redirect URI on the one OAuth application, alongside the login
// one. Renaming it is a Discord portal change, not just a route change — see
// tests/Feature/Join/JoinRedirectUriTest.php.
Route::get('/join', [JoinController::class, 'show'])->name('join');

Route::get('/join/discord', [JoinController::class, 'redirect'])
    ->middleware('throttle:10,1')
    ->name('join.redirect');

Route::get('/join/callback', [JoinController::class, 'callback'])
    ->middleware('throttle:10,1')
    ->name('join.callback');

// Discord is the only way in, so the route Laravel redirects guests to *is* the
// Discord handoff. There is no login form to design because there is nothing to
// type. The callback path is fixed at /auth/discord/callback in every environment
// and is registered as a redirect URI on the one OAuth application.
Route::get('/auth/discord/redirect', [DiscordLoginController::class, 'redirect'])
    ->middleware('throttle:10,1')
    ->name('login');

Route::get('/auth/discord/callback', [DiscordLoginController::class, 'callback'])
    ->middleware('throttle:10,1')
    ->name('login.callback');

// POST only. A logout on GET can be fired by any <img src> a member loads.
Route::post('/logout', [DiscordLoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    // Placeholder. The real profile is TWO-29 (backend) and TWO-30 (UI); this
    // exists so signing in has somewhere to land. Delete it when they ship.
    Route::view('/profile', 'profile')->name('profile');
});
