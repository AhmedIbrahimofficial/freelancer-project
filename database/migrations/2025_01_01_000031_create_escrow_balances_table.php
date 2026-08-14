<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrow_balances', function (Blueprint $table) {
            $table->id();
            $table->uuid('contract_id')->unique();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->decimal('held_amount', 12, 2)->default(0.00);
            $table->decimal('released_amount', 12, 2)->default(0.00);
            $table->decimal('refunded_amount', 12, 2)->default(0.00);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['unfunded', 'funded', 'partial', 'released', 'refunded'])->default('unfunded');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_balances');
    }
};
