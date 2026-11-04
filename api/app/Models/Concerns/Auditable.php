<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * SRS 25 - Writes an audit row whenever the model is created, changed or
 * deleted.
 *
 * Hooking model events rather than controllers means a record changed from an
 * artisan command, a queued job or the biometric sync agent is logged just the
 * same. An audit trail with holes in it is worse than none, because it invites
 * trust it has not earned.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->recordAudit('created', null, $model->auditableAttributes($model->getAttributes())));

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);

            $model->recordAudit(
                'updated',
                $model->auditableAttributes($old),
                $model->auditableAttributes($changes),
            );
        });

        static::deleted(function (Model $model) {
            $event = method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
                ? 'force_deleted'
                : 'deleted';

            $model->recordAudit($event, $model->auditableAttributes($model->getOriginal()), null);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->recordAudit('restored', null, null));
        }
    }

    /**
     * Attributes every model keeps out of the audit trail.
     *
     * Separate from auditExclude() so a model can override that method and
     * still extend this list - `parent::` cannot reach a trait method, and a
     * model that redeclares the whole list silently loses the defaults.
     *
     * @return array<int, string>
     */
    protected function defaultAuditExclude(): array
    {
        return ['password', 'remember_token', 'updated_at', 'created_at'];
    }

    /**
     * Attributes excluded from the audit trail. Override per model to keep
     * secrets and high-churn counters out of the log, spreading
     * defaultAuditExclude() into the returned array.
     *
     * @return array<int, string>
     */
    protected function auditExclude(): array
    {
        return $this->defaultAuditExclude();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditExclude()));
    }

    /** Human-readable description of the affected record. */
    public function auditDescription(string $event): string
    {
        $name = class_basename($this);
        $label = $this->getAttribute('full_name')
            ?? $this->getAttribute('name')
            ?? $this->getAttribute('reference')
            ?? $this->getKey();

        return "{$name} {$event}: {$label}";
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function recordAudit(string $event, ?array $old = null, ?array $new = null): void
    {
        $user = Auth::user();

        AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'user_role' => $user && method_exists($user, 'getRoleNames')
                ? $user->getRoleNames()->implode(', ')
                : null,
            'branch_id' => $this->getAttribute('branch_id') ?? $user?->branch_id,
            'event' => $event,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'description' => $this->auditDescription($event),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
            'url' => substr((string) Request::fullUrl(), 0, 512),
            'method' => Request::method(),
            'created_at' => now(),
        ]);
    }
}
