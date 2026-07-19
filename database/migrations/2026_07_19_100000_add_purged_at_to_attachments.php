<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // A purged attachment keeps its row as an honest metadata stub
            // ("file not kept") — the stored file itself is gone.
            $table->timestamp('purged_at')->nullable()->after('source_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });
    }
};
