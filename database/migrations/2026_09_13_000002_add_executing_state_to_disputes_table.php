<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds execution_state to disputes to support atomic execution claim.
 *
 * Pattern:
 *   1. Short DB transaction: lock row, check state, write 'claiming' → commit
 *   2. Stripe call (outside any transaction)
 *   3. Write 'complete' state
 *
 * This means a second concurrent request sees 'claiming' immediately after
 * step 1 completes — even if the first process crashes between steps 1 and 3.
 * Without this, two processes can both read "not executed" and both proceed
 * to call Stripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->enum('execution_state', [
                'idle',      // not yet executed
                'claiming',  // atomic claim written — Stripe call in progress
                'complete',  // both Stripe call and DB write succeeded
            ])->default('idle')->after('split_state');
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropColumn('execution_state');
        });
    }
};
