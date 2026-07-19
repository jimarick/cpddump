<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The one dedupe that must survive deletion: binned calendar events
        // must never resurrect on the next weekly sync. UID + user, nothing
        // else — no content.
        Schema::create('dismissed_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('uid');
            $table->timestamp('dismissed_at');
            $table->unique(['user_id', 'uid']);
        });

        // Carry existing dismissals of calendar events into the new table
        // before their skeleton rows are purged.
        DB::statement("
            INSERT INTO dismissed_calendar_events (user_id, uid, dismissed_at)
            SELECT user_id, external_id, COALESCE(resolved_at, NOW())
            FROM inbox_items
            WHERE status = 'dismissed' AND source = 'calendar' AND external_id IS NOT NULL
            ON CONFLICT (user_id, uid) DO NOTHING
        ");

        // One-time ghost purge: dismissed items and soft-deleted activities
        // have kept full content 'for audit' — delete means delete now.
        DB::statement("
            DELETE FROM attachments WHERE attachable_type = 'App\\Models\\InboxItem'
            AND attachable_id IN (SELECT id FROM inbox_items WHERE status = 'dismissed')
        ");
        DB::statement("DELETE FROM inbox_items WHERE status = 'dismissed'");

        DB::statement('
            DELETE FROM inbox_items WHERE activity_id IN
            (SELECT id FROM activities WHERE deleted_at IS NOT NULL)
        ');
        DB::statement('DELETE FROM activities WHERE deleted_at IS NOT NULL');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::dropIfExists('dismissed_calendar_events');
    }
};
