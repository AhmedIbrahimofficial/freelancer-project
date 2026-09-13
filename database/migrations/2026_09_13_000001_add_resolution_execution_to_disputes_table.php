<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks whether the financial action (Stripe transfer / refund) following a
 * dispute resolution has been executed. This separates the resolution decision
 * from the money movement — they are explicitly two separate steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            // When the Stripe action was executed (null = not yet executed)
            $table->timestamp('resolution_executed_at')->nullable()->after('resolved_at');

            // Which admin/mediator triggered the Stripe action
            $table->foreignId('executed_by')
                ->nullable()
                ->after('resolution_executed_at')
                ->constrained('users')
                ->nullOnDelete();

            // Stripe reference for the executed action (transfer_id or refund_id)
            $table->string('resolution_stripe_reference')->nullable()->after('executed_by');

            // For resolved_split: what percentage (0–100) goes to the freelancer.
            // NULL means not a split resolution (or full resolution).
            // resolved_freelancer implicitly = 100, resolved_client implicitly = 0.
            // Stored as DECIMAL(5,2) to allow e.g. 66.67%
            $table->decimal('split_freelancer_percent', 5, 2)->nullable()->after('resolution_stripe_reference');

            // Split execution can be partially complete (transfer done, refund pending).
            // These track each leg independently for reconciliation.
            $table->string('split_transfer_id')->nullable()->after('split_freelancer_percent');
            $table->string('split_refund_id')->nullable()->after('split_transfer_id');
            $table->enum('split_state', ['pending', 'transfer_done', 'refund_done', 'complete', 'partial_failure'])
                ->nullable()
                ->after('split_refund_id');
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropForeign(['executed_by']);
            $table->dropColumn([
                'resolution_executed_at',
                'executed_by',
                'resolution_stripe_reference',
                'split_freelancer_percent',
                'split_transfer_id',
                'split_refund_id',
                'split_state',
            ]);
        });
    }
};
