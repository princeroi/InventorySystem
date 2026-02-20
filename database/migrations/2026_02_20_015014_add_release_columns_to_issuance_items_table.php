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
        Schema::table('issuance_items', function (Blueprint $table) {
            $table->integer('released_quantity')->nullable()->after('quantity');
            $table->integer('remaining_quantity')->nullable()->after('released_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issuance_items', function (Blueprint $table) {
            //
        });
    }
};
