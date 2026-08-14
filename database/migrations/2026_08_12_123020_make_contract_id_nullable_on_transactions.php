<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payout transactions (freelancer withdrawals) are not tied to a specific contract,
 * so contract_id must be nullable.
 * Also adds 'payout' as a valid transfer type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Drop the foreign key constraint before changing the column
            $table->dropForeign(['contract_id']);
            $table->uuid('contract_id')->nullable()->change();
            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->uuid('contract_id')->nullable(false)->change();
            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->cascadeOnDelete();
        });
    }
};
