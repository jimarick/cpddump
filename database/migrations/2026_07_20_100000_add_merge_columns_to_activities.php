<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            // A merged activity's sources stay as real rows pointing at the
            // parent — hidden from lists and stats, restored on un-merge by
            // nulling the pointer. nullOnDelete is only the DB backstop; the
            // model's deleting hook cascades children properly.
            $table->foreignId('merged_into_activity_id')
                ->nullable()
                ->constrained('activities')
                ->nullOnDelete();
            $table->timestamp('merged_at')->nullable();

            // Promoted straight from a Ready inbox item inside a merge —
            // never individually reviewed. Shapes the un-merge indicator.
            $table->boolean('merge_unreviewed')->default(false);

            // Set on release so a split entry can say it was once merged.
            $table->timestamp('unmerged_at')->nullable();

            $table->index('merged_into_activity_id');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_activity_id');
            $table->dropColumn(['merged_at', 'merge_unreviewed', 'unmerged_at']);
        });
    }
};
