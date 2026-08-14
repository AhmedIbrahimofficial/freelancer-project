<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reputation_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('disputed_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->decimal('on_time_rate', 5, 2)->default(0.00);   // percentage 0-100
            $table->decimal('avg_rating', 3, 2)->default(0.00);     // 0.00 - 5.00
            $table->unsignedInteger('total_ratings')->default(0);
            $table->decimal('total_earned', 14, 2)->default(0.00);  // freelancers only
            $table->decimal('total_spent', 14, 2)->default(0.00);   // clients only
            $table->timestamp('last_computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_stats');
    }
};
