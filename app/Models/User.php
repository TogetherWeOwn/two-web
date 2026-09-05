<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'discord_id',
        'username',
        'display_name',
        'avatar',
        'is_moderator',
        'discord_joined_at',
        'discord_synced_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_moderator' => 'boolean',
            'discord_joined_at' => 'datetime',
            'discord_synced_at' => 'datetime',
        ];
    }

    /**
     * Same rule as the `access-admin` gate: moderators and nobody else, with
     * `is_moderator` recomputed from Discord roles at every login. Filament's
     * Authenticate middleware turns a false here into a 403 for a signed-in
     * member — not a redirect to login, which they are already past.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->can('access-admin');
    }

    /**
     * There is no `name` column — identity comes from Discord, which gives a
     * display name (changeable) and a username (unique). Filament renders this
     * in the panel's user menu and would fatal on the missing attribute.
     */
    public function getFilamentName(): string
    {
        return $this->display_name ?? $this->username ?? 'Unknown member';
    }

    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** @return HasMany<Rsvp, $this> */
    public function rsvps(): HasMany
    {
        return $this->hasMany(Rsvp::class);
    }
}
