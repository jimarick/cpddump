<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('activity_type_id')->constrained();
            $table->string('title');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('cpd_points', 6, 2)->default(0);
            $table->string('organisation')->nullable();
            $table->text('details')->nullable();
            $table->jsonb('reflection')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'appraisal_period_id', 'starts_on']);
        });

        Schema::table('inbox_items', function (Blueprint $table) {
            $table->foreignId('activity_id')->nullable()->after('ai_warnings')->constrained()->nullOnDelete();
        });

        Schema::create('activity_category', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'category_id']);
        });

        Schema::create('activity_framework_domain', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('framework_domain_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'framework_domain_id']);
        });

        Schema::create('activity_framework_attribute', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('framework_attribute_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'framework_attribute_id']);
        });

        Schema::create('activity_project', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->primary(['activity_id', 'project_id']);
        });

        Schema::create('activity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('linked_activity_id')->constrained('activities')->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'linked_activity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_links');
        Schema::dropIfExists('activity_project');
        Schema::dropIfExists('activity_framework_attribute');
        Schema::dropIfExists('activity_framework_domain');
        Schema::dropIfExists('activity_category');

        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
        });

        Schema::dropIfExists('activities');
    }
};
