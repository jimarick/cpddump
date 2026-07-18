<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
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
}
