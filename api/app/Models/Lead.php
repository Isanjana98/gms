<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Somebody who enquired through the website and has not joined yet. */
#[Fillable([
    'branch_id', 'name', 'telephone', 'email', 'message', 'membership_plan_id',
    'source', 'status', 'notes', 'converted_member_id', 'contacted_at', 'handled_by', 'ip_address',
])]
class Lead extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    protected function casts(): array
    {
        return ['contacted_at' => 'datetime'];
    }

    /**
     * An enquiry is a stranger's contact details submitted through a public
     * form. Keeping the message and phone number out of the audit log means
     * the audit trail records that a lead was handled without copying
     * someone's details into a second table that is never pruned.
     *
     * @return array<int, string>
     */
    protected function auditExclude(): array
    {
        return [...$this->defaultAuditExclude(), 'message', 'telephone', 'email', 'ip_address', 'notes'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function convertedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'converted_member_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** Still worth a phone call. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['NEW', 'CONTACTED']);
    }

    public function auditDescription(string $event): string
    {
        return "Lead {$event}: {$this->name}";
    }
}
