<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FeaturedContentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A landing-page slot a moderator controls: an announcement, a spotlighted
 * event, a community link. Managed only through the admin panel; the landing
 * page reads `currentlyVisible()` and nothing else.
 *
 * @property int $id
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property string|null $image_url
 * @property string|null $image_alt
 * @property bool $is_published
 * @property int $position
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $created_by
 */
class FeaturedContent extends Model
{
    /** @use HasFactory<FeaturedContentFactory> */
    use HasFactory;

    use LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'body',
        'url',
        'image_url',
        'image_alt',
        'is_published',
        'position',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'position' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * The audit trail TOG-54 asks for: who changed what, when. Only changed
     * attributes are stored, and empty events (a save that changed nothing)
     * write no row, so the log answers questions instead of padding itself.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The alt text every featured <img> renders with. A moderator-written
     * description wins; when the row predates the column (or the moderator
     * cleared it) the headline stands in rather than an empty string, so a
     * screen-reader visitor always gets something meaningful. Title is
     * non-nullable, so this is never empty when an image is present.
     */
    public function imageAltText(): string
    {
        $alt = trim((string) ($this->getAttribute('image_alt') ?? ''));

        return $alt !== '' ? $alt : (string) $this->getAttribute('title');
    }

    /** What the landing page shows, in the order moderators arranged it. */
    /** @param  Builder<self>  $query */
    public function scopeCurrentlyVisible(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
