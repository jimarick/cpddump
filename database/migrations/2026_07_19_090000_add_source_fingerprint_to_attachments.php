<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // "{original filename}:{original byte count}" — captured before
            // normalisation so dedupe recognises a re-upload of the same
            // source file even though we store a converted copy.
            $table->string('source_fingerprint')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('source_fingerprint');
        });
    }
};
