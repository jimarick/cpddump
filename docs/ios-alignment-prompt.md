# Prompt for the iOS-app Claude session — align with the file-pipeline build

Copy everything below the line into a Claude Code session in `~/Code/cpd-dump-ios`.

---

The CPD Dump backend (`~/Code/CPD-Dump`, live at cpddump.com) shipped a large
storage/privacy overhaul on 2026-07-19: every upload is now normalised at
ingest, most source content is deleted after AI analysis, files are
delete-by-default at approval, and dismissal/deletion are hard deletes. Audit
this iOS app against those changes and fix what needs fixing.

Backend commit range to review: run this in the backend repo —
`git -C ~/Code/CPD-Dump log --stat 84f91d2^..d0724e6`
(reference doc: `~/Code/CPD-Dump/docs/file-pipeline-plan.md`; API controllers:
`app/Http/Controllers/Api/`, routes in `routes/api.php`).

## Behaviour changes that may affect this app

1. **Dismiss is now a hard delete.**
   `DELETE /api/v1/inbox-items/{id}` now returns
   `{"status": "dismissed", "deleted": true}` and the item row NO LONGER
   EXISTS afterwards — any subsequent fetch of that id is a 404. Check: does
   the app refetch, cache, or locally mark items as "dismissed" expecting the
   record to persist? Any sync logic keyed on status transitions must instead
   treat binned items as gone.

2. **Activities are hard-deleted too** (no soft-delete). A deleted activity's
   id will 404 forever, and deleting an activity also deletes its originating
   inbox item. Check cached timelines / local stores.

3. **HEIC now works server-side.** The server decodes HEIC/HEIF (and
   tiff/avif/bmp) and stores everything as a normalised ≤1600px EXIF-stripped
   JPEG. If this app was blocked on, or working around, HEIC uploads — the
   workaround can go. Client-side HEIC→JPEG conversion before upload is still
   *worth doing* as an optimisation (a ~6MB HEIC becomes ~250KB before it
   crosses the network), but it is no longer a correctness requirement.

4. **Attachment metadata changes after upload.** Uploaded images come back as
   `image/jpeg` with a `.jpg` storage path regardless of what was sent (the
   `original_filename` keeps the user's name, e.g. `photo.heic`). Don't assume
   the stored mime matches the uploaded mime.

5. **Files disappear on a schedule — expect 404s on attachment URLs.**
   - Audio (voice notes, mp3/wav/m4a): file deleted immediately after
     transcription — before the user even reviews.
   - All other files: deleted at approval unless the user explicitly kept
     them, or when an item is binned.
   - Attachments now carry a `purged_at` timestamp server-side; the web UI
     shows them as "file not kept" stubs. Check whether the API responses this
     app consumes include a `purged` flag (web serialisers do; API serialisers
     may not yet — if the app renders attachment links, it needs either the
     flag exposed in the API or graceful 404 handling).

6. **Raw source text is scrubbed after analysis.** Email bodies, voice-note
   transcripts, spreadsheet rows and fetched page text are deleted from the
   item's payload the moment AI analysis succeeds. If any screen displays
   `raw_payload.body` / `raw_payload.transcript` etc., it will now usually be
   absent — the AI draft (`ai_analysis`) is the thing to display.

7. **Approval has two new inputs and one new failure mode.** (Check whether
   this app approves items at all; if it's capture-only, note that and skip.)
   - `keep_attachment_ids: [int]` — files not listed are deleted at approval
     (server default is delete; the web asks per file with a warning).
   - `pii_ack: bool` — if the item has `pii_gate: true` (flagged patient
     information still held in a stored file or user-typed text), approval
     WITHOUT `pii_ack=true` fails validation with an error keyed `pii`.
     The web offers "Remove patient info" (`POST /inbox/{id}/remove-pii`,
     currently web-only — flag if the app needs API parity) or
     "Keep — I've checked".

8. **New accepted upload types** — the allowlist is now: pdf, jpg, jpeg, png,
   webp, heic, heif, gif, tiff, tif, avif, bmp, doc, docx, ppt, pptx, txt,
   ics, eml, csv, xlsx, xls, md, rtf, mp3, wav, m4a. If the app restricts its
   file/document picker, it can widen to match. Renamed executables are
   rejected server-side by content sniffing; per-user storage quota is 500MB
   (files silently not stored beyond it; text extraction still happens).

9. **Any audio now transcribes** regardless of source — an mp3 attached to a
   generic upload routes through transcription, not just voice notes.

## What to produce

1. A findings list: each place the app's code or assumptions conflict with the
   above, with file:line references.
2. The fixes, applied — prioritising: dismissal/deletion handling (1, 2),
   stale-file 404 handling (5), and payload display (6).
3. A note of anything requiring backend API additions (e.g. `purged` flag or
   `remove-pii` in the API) rather than iOS changes — do not work around
   missing API surface silently.
