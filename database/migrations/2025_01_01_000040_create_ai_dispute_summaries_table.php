<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_dispute_summaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('dispute_id');
            $table->foreign('dispute_id')->references('id')->on('disputes')->cascadeOnDelete();
            $table->enum('type', ['summary', 'suggestion'])->default('summary');
            $table->longText('summary_text');
            $table->text('suggested_resolution')->nullable();
            $table->string('model_version')->default('claude-3-5-sonnet');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->timestamps();

            $table->index(['dispute_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_dispute_summaries');
    }
};
