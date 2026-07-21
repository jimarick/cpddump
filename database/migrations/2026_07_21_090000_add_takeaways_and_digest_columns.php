<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->jsonb('nuggets')->nullable();
            $table->jsonb('actions')->nullable();
            $table->text('source_notes')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('weekly_learning_recap_enabled')->default(true)->after('push_weekly_nudge_enabled');
            $table->boolean('monthly_digest_email_enabled')->default(true)->after('weekly_learning_recap_enabled');
            $table->boolean('push_morning_gem_enabled')->default(true)->after('monthly_digest_email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['nuggets', 'actions', 'source_notes']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['weekly_learning_recap_enabled', 'monthly_digest_email_enabled', 'push_morning_gem_enabled']);
        });
    }
};
