<?php

namespace Jiannius\Myinvois\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A submission POST the package sent (or was about to send) for one document and one parent.
 *
 * Pending from right before the POST leaves until LHDN answers definitely. One that stays
 * pending (a timeout, a 5xx, a process that died) means LHDN may hold the document although
 * no myinvois_documents row says so: the next submission of that parent looks it up first
 * (see Myinvois::submitDocuments()), and Myinvois::hasPendingAttempt() lets the app hold off
 * edits meanwhile.
 */
class MyinvoisSubmitAttempt extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const CLOSED = 'closed';

    /** Outcomes of a closed attempt. */
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const ADOPTED = 'adopted';

    public const FOUND_INVALID = 'found_invalid';

    public const NOT_FOUND = 'not_found';

    protected $table = 'myinvois_submit_attempts';

    protected $guarded = [];

    protected $casts = [
        'is_preprod' => 'boolean',
        'is_consolidate' => 'boolean',
        'row_numbers' => 'array',
        'closed_at' => 'datetime',
    ];

    public function parent() : MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query) : void
    {
        $query->where('status', static::PENDING);
    }

    public function scopeForParent(Builder $query, Model $parent, bool $preprod) : void
    {
        $query->where('parent_type', $parent->getMorphClass())
            ->where('parent_id', (string) $parent->getKey())
            ->where('is_preprod', $preprod);
    }

    /**
     * Close the attempt with an outcome (one of the constants above).
     */
    public function close(string $outcome, array $attributes = []) : void
    {
        $this->update([...$attributes, 'status' => static::CLOSED, 'outcome' => $outcome, 'closed_at' => now()]);
    }
}
