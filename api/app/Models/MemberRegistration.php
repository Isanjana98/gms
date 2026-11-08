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

/**
 * Somebody who filled in the registration form from the QR code at the desk.
 *
 * Not a member. See the migration for why that separation is the point of
 * this table rather than an implementation detail.
 */
#[Fillable([
    'branch_id',
    'full_name', 'nic_passport', 'date_of_birth', 'gender',
    'address', 'city', 'telephone', 'email',
    'emergency_contact_name', 'emergency_contact_number',
    'parq_version', 'parq_answers', 'parq_flag_count', 'custom_answers',
    'signature_path', 'signed_name', 'signed_at', 'declaration',
    'ip_address', 'user_agent',
    'status', 'reviewed_by', 'reviewed_at', 'review_notes', 'member_id',
])]
// The signature is a file on the private disk; its path is an internal
// detail and never anybody's business over the wire.
#[Hidden(['signature_path'])]
class MemberRegistration extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    /** Signatures live on the private disk, never the public one. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'parq_answers' => 'array',
            'custom_answers' => 'array',
            'parq_flag_count' => 'integer',
            'signed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The member this became, once somebody approved it. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'PENDING');
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    /**
     * Whether any PAR-Q answer means "speak to a doctor first".
     *
     * Read from the stored count rather than recalculated, because the
     * questionnaire wording can change: recounting an old submission against
     * today's questions would answer a question nobody was asked.
     */
    public function hasHealthFlags(): bool
    {
        return $this->parq_flag_count > 0;
    }

    /**
     * The questions that were flagged, paired with the wording in force when
     * this was signed.
     *
     * @return array<int, array{id: string, text: string|null}>
     */
    public function flaggedQuestions(): array
    {
        return ParQ::flagged($this->parq_answers ?? []);
    }
}
