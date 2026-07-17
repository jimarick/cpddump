<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\InboxItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['activities' => [], 'inbox' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
        $user = $request->user();

        $activities = $user->activities()
            ->where(function ($q) use ($like) {
                $q->where('title', 'ilike', $like)
                    ->orWhere('details', 'ilike', $like)
                    ->orWhere('organisation', 'ilike', $like)
                    ->orWhereRaw('reflection::text ilike ?', [$like]);
            })
            ->with('type:id,name,color')
            ->orderByDesc('starts_on')
            ->limit(8)
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'date' => $a->starts_on?->toDateString(),
                'points' => (float) $a->cpd_points,
                'type' => $a->type->name,
                'color' => $a->type->color,
            ]);

        $inbox = $user->inboxItems()
            ->open()
            ->where(function ($q) use ($like) {
                $q->whereRaw('raw_payload::text ilike ?', [$like])
                    ->orWhereRaw('ai_analysis::text ilike ?', [$like]);
            })
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (InboxItem $i) => [
                'id' => $i->id,
                'title' => $i->ai_analysis['title'] ?? $i->raw_payload['title'] ?? $i->raw_payload['subject'] ?? 'Untitled evidence',
                'status' => $i->status->value,
                'source' => $i->source->label(),
            ]);

        return response()->json(['activities' => $activities, 'inbox' => $inbox]);
    }
}
