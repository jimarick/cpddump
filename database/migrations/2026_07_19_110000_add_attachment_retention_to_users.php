<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ask (default): each keepable file prompts at approval.
            // always: keep files without asking. never: purge without asking.
            $table->string('attachment_retention')->default('ask')->after('weekly_email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('attachment_retention');
        });
    }
};
