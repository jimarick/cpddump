<?php

namespace App\Http\Controllers\Api;

use App\Enums\EvidenceSource;
use App\Http\Controllers\Controller;
use App\Services\EvidenceIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The capture endpoint for the future companion app: text notes, voice
 * recordings, photos and documents, straight into the evidence inbox.
 */
class InboxItemApiController extends Controller
{
    public function store(Request $request, EvidenceIngestor $ingestor): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255', 'required_without_all:files,audio'],
            'details' => ['nullable', 'string', 'max:20000'],
            'audio' => ['nullable', 'file', 'max:51200', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav,audio/x-m4a,video/webm'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:25600', 'mimes:pdf,jpg,jpeg,png,webp,heic,gif,doc,docx,txt'],
        ]);

        $audio = $request->file('audio');
        $audio = is_array($audio) ? null : $audio;

        $uploaded = $request->file('files');
        $files = is_array($uploaded) ? array_values($uploaded) : [];

        if ($audio !== null) {
            $files[] = $audio;
        }

        $item = $ingestor->ingest(
            user: $request->user(),
            source: $audio ? EvidenceSource::VoiceNote : ($files !== [] ? EvidenceSource::Upload : EvidenceSource::Manual),
            rawPayload: array_filter([
                'title' => $validated['title'] ?? null,
                'details' => $validated['details'] ?? null,
            ]),
            files: $files,
        );

        if (! $item) {
            return response()->json(['message' => 'Matched an ignore rule.'], 200);
        }

        return response()->json([
            'id' => $item->id,
            'status' => $item->status->value,
        ], 201);
    }
}
