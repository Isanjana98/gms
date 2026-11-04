<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\HealthScreening;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sequential, human-readable references: GM-2026-00001, RCP-2026-00042.
 *
 * Two receptionists registering members at the same moment must not be handed
 * the same number. A plain MAX(...) + 1 read will do exactly that, so the read
 * is taken with a row lock inside the caller's transaction and the unique
 * index on each column is the last line of defence: on the rare collision the
 * insert fails and we retry rather than quietly issuing a duplicate receipt.
 */
class NumberGenerator
{
    private const MAX_ATTEMPTS = 5;

    public function memberNumber(): string
    {
        return $this->next(Member::class, 'membership_number', config('gms.numbering.member'));
    }

    public function membershipReference(): string
    {
        return $this->next(Membership::class, 'reference', config('gms.numbering.membership'));
    }

    public function invoiceNumber(): string
    {
        return $this->next(Invoice::class, 'invoice_number', config('gms.numbering.invoice'));
    }

    public function receiptNumber(): string
    {
        return $this->next(Payment::class, 'receipt_number', config('gms.numbering.receipt'));
    }

    public function trainerCode(): string
    {
        return $this->next(Trainer::class, 'trainer_code', config('gms.numbering.trainer'));
    }

    public function expenseReference(): string
    {
        return $this->next(Expense::class, 'reference', config('gms.numbering.expense'));
    }

    public function screeningReference(): string
    {
        return $this->next(HealthScreening::class, 'reference', config('gms.numbering.screening'));
    }

    /**
     * Run $callback, retrying if a unique-constraint collision means two
     * requests generated the same reference at once.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withRetry(callable $callback): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                // Brief, growing pause so the racing request commits first
                // and our next read sees its number.
                usleep(random_int(10_000, 50_000) * $attempt);
            }
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function next(string $modelClass, string $column, string $prefix): string
    {
        $year = now()->format('Y');
        $pattern = "{$prefix}-{$year}-";
        $padding = (int) config('gms.numbering.padding', 5);

        /** @var Model $model */
        $model = new $modelClass;

        $query = $model->newQuery()
            ->where($column, 'like', $pattern.'%')
            ->orderByDesc($column);

        // Soft-deleted rows still own their number - reusing it would make two
        // different records share a receipt number in the audit trail.
        if (method_exists($model, 'getDeletedAtColumn')) {
            $query->withTrashed();
        }

        // Only lock inside a transaction; outside one the lock is pointless and
        // some drivers object to it.
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $latest = $query->value($column);

        $sequence = $latest
            ? ((int) substr($latest, strlen($pattern))) + 1
            : 1;

        return $pattern.str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);
    }
}
