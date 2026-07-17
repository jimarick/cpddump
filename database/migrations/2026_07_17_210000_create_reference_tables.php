<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });

        Schema::create('activity_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profession_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('color', 16);
            $table->string('icon', 64);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['profession_id', 'slug']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profession_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['profession_id', 'slug']);
        });

        Schema::create('framework_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profession_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['profession_id', 'code']);
        });

        Schema::create('framework_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_domain_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['framework_domain_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_attributes');
        Schema::dropIfExists('framework_domains');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('activity_types');
        Schema::dropIfExists('professions');
    }
};
