<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A form request on a route behind `auth`.
 *
 * `$request->user()` is typed as a nullable Authenticatable, so every caller that
 * needs a concrete User either narrows it or lies to the type checker. Narrow it
 * once, here, and throw the same thing the middleware would have thrown if the
 * route were ever moved out from behind it.
 */
abstract class AuthenticatedRequest extends FormRequest
{
    /** @throws AuthenticationException */
    public function actor(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
