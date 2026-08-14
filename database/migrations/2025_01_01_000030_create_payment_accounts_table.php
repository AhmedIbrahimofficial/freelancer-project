<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('stripe_account_id')->nullable()->unique();
            $table->enum('account_type', ['express', 'custom', 'standard'])->default('express');
            $table->enum('status', ['pending', 'active', 'restricted', 'disabled'])->default('pending');
            $table->boolean('payout_enabled')->default(false);
            $table->boolean('charges_enabled')->default(false);
            $table->string('default_currency', 3)->default('USD');
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');
    }
};
