<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 24);
            $table->string('status', 16)->default('pending');
            $table->jsonb('raw_payload')->default('{}');
            $table->string('content_hash', 64)->nullable();
            $table->string('external_id')->nullable();
            $table->jsonb('ai_analysis')->nullable();
            $table->jsonb('ai_warnings')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('analysed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['user_id', 'content_hash']);
            $table->unique(['user_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
    }
};
