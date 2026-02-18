<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->date('pending_at')->nullable()->change();
            $table->date('released_at')->nullable()->change();
            $table->date('issued_at')->nullable()->change();
            $table->date('returned_at')->nullable()->change();
            $table->date('cancelled_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->date('pending_at')->nullable(false)->change();
            $table->date('released_at')->nullable(false)->change();
            $table->date('issued_at')->nullable(false)->change();
            $table->date('returned_at')->nullable(false)->change();
            $table->date('cancelled_at')->nullable(false)->change();
        });
    }
};