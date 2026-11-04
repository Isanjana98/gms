<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sign-in codes for the member app go out through the ordinary outbox.
 *
 * Sent that way on purpose rather than through a separate path: a code then
 * follows whatever SMS provider the gym has configured, is retried by the same
 * dispatcher, and appears in the same delivery log as every other message. A
 * second sending mechanism would be a second thing to configure and a second
 * place for a message to go missing without anyone noticing.
 *
 * A raw statement rather than ->change(): altering an enum in place needs the
 * full column definition restated, and doing it through the schema builder on
 * MariaDB drops the default.
 */
return new class extends Migration
{
    private const TYPES = [
        'MEMBERSHIP_EXPIRY_REMINDER',
        'MEMBERSHIP_EXPIRED',
        'PAYMENT_DUE',
        'PAYMENT_CONFIRMATION',
        'NEW_MEMBERSHIP',
        'PROMOTIONAL',
        'SYSTEM',
    ];

    public function up(): void
    {
        $values = $this->quote([...self::TYPES, 'LOGIN_CODE']);

        DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }

    public function down(): void
    {
        // Rows using the new value would violate the narrower enum. They are
        // spent one-time codes, so there is nothing to preserve.
        DB::table('notifications')->where('type', 'LOGIN_CODE')->delete();

        $values = $this->quote(self::TYPES);

        DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }

    /** @param  array<int, string>  $values */
    private function quote(array $values): string
    {
        return implode(',', array_map(fn (string $value) => "'{$value}'", $values));
    }
};
