<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->uuid('milestone_id')->nullable();
            $table->foreign('milestone_id')->references('id')->on('milestones')->nullOnDelete();
            $table->foreignId('raised_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_mediator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', [
                'open',
                'under_review',
                'awaiting_evidence',
                'resolved_client',
                'resolved_freelancer',
                'resolved_split',
                'closed',
            ])->default('open');
            $table->text('reason');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
