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
        Schema::create('restocks', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_name');
            $table->string('ordered_by');
            $table->date('ordered_at');
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('restock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('size');
            $table->integer('quantity');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restock_items');
        Schema::dropIfExists('restocks');
    }
};
