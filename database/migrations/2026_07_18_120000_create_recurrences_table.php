<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // scheduled | expectation
            $table->string('title');
            $table->foreignId('activity_type_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('cpd_points', 6, 2)->default(0);
            $table->string('organisation')->nullable();
            $table->string('frequency', 16)->nullable(); // scheduled: weekly | fortnightly | monthly
            $table->date('next_due_on')->nullable(); // scheduled: next draft date
            $table->unsignedSmallInteger('expected_per_year')->nullable(); // expectation
            $table->date('last_prompted_on')->nullable();
            $table->date('last_matched_on')->nullable();
            $table->string('reminder', 16)->default('weekly'); // same_day | weekly | none
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        Schema::table('inbox_items', function (Blueprint $table) {
            $table->foreignId('recurrence_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('recurrence_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_id');
        });

        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_id');
        });

        Schema::dropIfExists('recurrences');
    }
};
