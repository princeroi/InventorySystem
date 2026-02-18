<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            // Each status gets its own timestamp column.
            // pending_at is set on creation; the others are set when the status transitions.
            $table->date('pending_at')->nullable()->after('issued_at');
            $table->date('released_at')->nullable()->after('pending_at');
            $table->date('returned_at')->nullable()->after('released_at');
            $table->date('cancelled_at')->nullable()->after('returned_at');

            // Rename existing issued_at → keep it, but it now specifically means
            // the date the record was marked "issued" (not the original creation date).
            // If you previously used issued_at as a general "record date", seed pending_at
            // from it in the seeder below.
        });

        // Backfill: for existing records, copy issued_at into the correct status column.
        DB::table('issuances')->orderBy('id')->each(function ($row) {
            $column = match ($row->status) {
                'pending'   => 'pending_at',
                'released'  => 'released_at',
                'issued'    => null, // already stored in issued_at
                'returned'  => 'returned_at',
                'cancelled' => 'cancelled_at',
                default     => null,
            };

            // For non-issued statuses, also populate pending_at with the original date
            // as a baseline if it's still null.
            DB::table('issuances')
                ->where('id', $row->id)
                ->update(array_filter([
                    'pending_at' => $row->issued_at, // baseline for all existing records
                    $column      => $column ? $row->issued_at : null,
                ]));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->dropColumn(['pending_at', 'released_at', 'returned_at', 'cancelled_at']);
        });
    }
};
