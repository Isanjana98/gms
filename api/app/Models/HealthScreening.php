<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use App\Support\ParQ;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A member's PAR-Q answers, the signature on them, and the review they triggered. */
#[Fillable([
    'member_id', 'branch_id', 'replaces_id', 'reference',
    'token_hash', 'token_expires_at', 'issued_at', 'issued_by',
    'status', 'answers', 'has_flags', 'flag_count', 'questionnaire_version',
    'terms_version', 'terms_hash', 'signature_path', 'signed_name', 'signed_at',
    'ip_address', 'user_agent', 'record_hash', 'document_id',
    'review_status', 'reviewed_by', 'reviewed_at', 'review_notes',
])]
// token_hash would let anyone who can read the API brute-force a link to
// somebody's health form; signature_path is an internal location on the
// private disk. Neither is ever anybody's business over the wire.
#[Hidden(['token_hash', 'signature_path'])]
class HealthScreening extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    /** Signatures live on the private disk, never the public one. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'has_flags' => 'boolean',
            'flag_count' => 'integer',
            'token_expires_at' => 'datetime',
            'issued_at' => 'datetime',
            'signed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(MemberDocument::class, 'document_id');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /* ------------------------------------------------------------- state */

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }

    /**
     * Whether the link still works.
     *
     * Expiry is checked here rather than relying on a scheduled job marking
     * rows EXPIRED: a job that has not run yet must not leave a stale link
     * working.
     */
    public function linkIsUsable(): bool
    {
        return $this->status === 'PENDING'
            && $this->token_hash !== null
            && $this->token_expires_at !== null
            && $this->token_expires_at->isFuture();
    }

    public function awaitsReview(): bool
    {
        return $this->review_status === 'PENDING';
    }

    /** The questions this member answered yes to. */
    public function flaggedQuestions(): array
    {
        return ParQ::flagged($this->answers ?? []);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeOutstandingReview(Builder $query): Builder
    {
        return $query->where('review_status', 'PENDING');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'COMPLETED');
    }
}
