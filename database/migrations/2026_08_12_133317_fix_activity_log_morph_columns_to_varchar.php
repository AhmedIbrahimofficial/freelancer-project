<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The activity_log table was created with subject_id and causer_id as bigint,
 * which works for integer PKs but truncates UUIDs (Dispute, Contract, Milestone).
 *
 * SQLite does not support ALTER TABLE MODIFY — it creates the table with
 * nullableMorphs() which already uses varchar, so no action needed there.
 * MySQL requires explicit ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: nullableMorphs() already creates subject_id as text — no action needed.
            return;
        }

        // MySQL / MariaDB: ALTER existing bigint columns to varchar
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('subject');
            $table->dropIndex('causer');
        });

        DB::statement('ALTER TABLE activity_log MODIFY subject_id VARCHAR(255) NULL');
        DB::statement('ALTER TABLE activity_log MODIFY causer_id VARCHAR(255) NULL');

        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id'], 'subject');
            $table->index(['causer_type', 'causer_id'], 'causer');
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('subject');
            $table->dropIndex('causer');
        });

        DB::statement('ALTER TABLE activity_log MODIFY subject_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE activity_log MODIFY causer_id BIGINT UNSIGNED NULL');

        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id'], 'subject');
            $table->index(['causer_type', 'causer_id'], 'causer');
        });
    }
};
