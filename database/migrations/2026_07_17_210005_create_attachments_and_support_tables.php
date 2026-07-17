<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('attachable');
            $table->string('disk', 32)->default('local');
            $table->string('path', 1024);
            $table->string('original_filename', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size')->default(0);
            $table->longText('extracted_text')->nullable();
            $table->timestamps();
        });

        Schema::create('ignore_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 24)->nullable();
            $table->string('field', 24);
            $table->string('operator', 16)->default('contains');
            $table->string('value', 512);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('url');
            $table->string('provider_hint', 16)->nullable();
            $table->string('status', 16)->default('active');
            $table->text('last_sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 32);
            $table->string('provider', 16);
            $table->string('model', 64);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost', 8, 4)->default(0);
            $table->boolean('used_user_key')->default(false);
            $table->nullableMorphs('generatable');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('generated_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->text('question')->nullable();
            $table->jsonb('params')->default('{}');
            $table->longText('content')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            $table->index(['user_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_reports');
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('calendar_feeds');
        Schema::dropIfExists('ignore_rules');
        Schema::dropIfExists('attachments');
    }
};
