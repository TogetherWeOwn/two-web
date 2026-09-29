<?php

namespace App\Models;

use Database\Factories\FaqVoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One was-this-helpful vote on an FAQ entry (TOG-8863).
 *
 * `entry` is the stable App\Support\FaqEntry slug, not the question text, so
 * rewording a question keeps its history. Exactly one voter half is set:
 * signed-in members are keyed by `user_id`, signed-out visitors by the random
 * first-party `faq_voter` cookie in `voter_key` — no PII, no IP stored.
 *
 * @property int $id
 * @property string $entry
 * @property bool $helpful
 * @property int|null $user_id
 * @property string|null $voter_key
 */
class FaqVote extends Model
{
    /** @use HasFactory<FaqVoteFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'entry',
        'helpful',
        'user_id',
        'voter_key',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'helpful' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
