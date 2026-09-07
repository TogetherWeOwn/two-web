<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\IanaTimeZone;

class UpdateProfileRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        if (! $user instanceof User) {
            return false;
        }

        return $this->actor()->can('updateProfile', $user);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('games_text')) {
            $this->merge([
                'games' => preg_split('/\R/', (string) $this->input('games_text')) ?: [],
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:1000'],
            'games' => ['present', 'array', 'max:20'],
            'games.*' => ['string', 'max:80'],
            'games_text' => ['sometimes', 'nullable', 'string', 'max:1700'],
            'timezone' => ['nullable', 'string', new IanaTimeZone],
        ];
    }

    /** @return array{bio: ?string, games: list<string>, timezone: ?string} */
    public function profileAttributes(): array
    {
        $validatedGames = $this->validated('games', []);
        $games = [];

        if (is_array($validatedGames)) {
            foreach ($validatedGames as $game) {
                if (! is_string($game)) {
                    continue;
                }

                $game = trim($game);
                if ($game !== '' && ! in_array($game, $games, true)) {
                    $games[] = $game;
                }
            }
        }

        $bio = $this->validated('bio');
        $timezone = $this->validated('timezone');

        return [
            'bio' => is_string($bio) && trim($bio) !== '' ? trim($bio) : null,
            'games' => $games,
            'timezone' => is_string($timezone) && $timezone !== '' ? $timezone : null,
        ];
    }
}
