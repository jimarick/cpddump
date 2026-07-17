<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('profession_id')->nullable()->after('remember_token')->constrained()->nullOnDelete();
            $table->boolean('is_admin')->default(false);
            $table->string('timezone', 64)->default('Europe/London');
            $table->string('inbound_email_token', 32)->nullable()->unique();
            $table->string('ai_provider', 16)->nullable();
            $table->text('ai_api_key')->nullable();
            $table->boolean('weekly_email_enabled')->default(true);
            $table->timestamp('onboarded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profession_id');
            $table->dropColumn([
                'is_admin',
                'timezone',
                'inbound_email_token',
                'ai_provider',
                'ai_api_key',
                'weekly_email_enabled',
                'onboarded_at',
            ]);
        });
    }
};
