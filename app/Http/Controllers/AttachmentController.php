<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Serve an evidence file inline — PDFs and images render in the
     * browser; anything else downloads.
     */
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->user_id === $request->user()->id, 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_filename);
    }

    /**
     * Delete a kept file after approval. The row stays as an honest
     * "not kept" stub — the written entry is untouched. Serves both the
     * web (redirect back) and the companion app (JSON).
     */
    public function destroy(Request $request, Attachment $attachment): RedirectResponse|JsonResponse
    {
        abort_unless($attachment->user_id === $request->user()->id, 404);

        $attachment->purgeToStub();
        $attachment->update(['extracted_text' => null]);

        if ($request->wantsJson()) {
            return response()->json(['status' => 'deleted']);
        }

        return back()->with('success', 'File deleted — your written entry is kept.');
    }
}
