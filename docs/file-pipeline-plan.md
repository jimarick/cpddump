# File Pipeline Plan — every type, normalised at the door

**Status: PLAN — nothing built yet.** Decided 2026-07-19 after confirming production
Laravel Cloud runs Imagick 7.1.2 with HEIC/HEIF/AVIF/WEBP/TIFF delegates and
unrestricted `proc_open` (`php artisan cpd:check-image-support`). No Lambda needed
for any of this. **Probe re-run on production 2026-07-19: PDF→image render ✔ —
Ghostscript is present and the policy allows it. Every pipeline below is
unblocked; no external compute required anywhere.**

## Principle

Nothing is stored as-received. Every upload passes through a per-type normaliser
**before** it reaches R2, so what we keep is always: small, readable by the AI,
stripped of hidden metadata, and in a format the user can open in any browser.
The as-received bytes exist only transiently (request memory / queue tmp) and are
never written to permanent storage.

```
upload / email attachment / iOS API
        │
        ▼
  EvidenceIngestor ── mime sniff (finfo, not extension) ── reject/flag unknown
        │
        ▼
  Normaliser (per type, below) ── clean copy → R2 ── original discarded
        │
        ▼
  AnalyzeInboxItem (sees only normalised artefacts)
        │
        ▼
  Lifecycle: approve → keep · dismiss → purge · audio → transcript kept, file deleted
```

## The full map — every type, today vs. under this plan

| Type | Today | Under this plan | Verdict |
|---|---|---|---|
| **Images** | | | |
| jpg / png / webp | Accepted; stored as-received, full size, EXIF+GPS intact; vision reads them | Normalised: auto-orient → sRGB → 1600px → EXIF stripped → JPEG q80 | Improved |
| gif | Accepted; stored as-is | First frame → same normaliser | Improved |
| heic / heif (iPhone default) | **Accepted but broken** — stored, but neither vision API can read it | Imagick decodes → normalised JPEG; AI only ever sees JPEG | **Fixed** |
| tiff / avif / bmp | Rejected | Accepted → normalised JPEG | **New** |
| **Documents** | | | |
| PDF — digital/text | Accepted; pdfparser text; original stored | Unchanged, plus ~10MB sanity cap (bigger = really a scan → scan path) | Unchanged |
| PDF — scanned | Accepted; 10–30MB stored as-is; sent raw to model (≤20pp gate) | Rasterised 150 DPI → pages normalised → recombined compact PDF (~1–2MB); AI gets page JPEGs | Improved |
| docx / pptx | Accepted; phpoffice text only — embedded images invisible to AI | Text **+** ZipArchive embedded-media extraction → images through normaliser → vision sees them; 25MB cap | Improved |
| doc / ppt (legacy OLE) | Accepted; thin extraction, silently | Same extraction, honesty-flagged when thin | Honest now |
| xlsx / xls | Rejected | PhpSpreadsheet → capped text (audit data, attendance) | **New** |
| **Text & data** | | | |
| txt | Accepted; text path | Unchanged | Unchanged |
| md / rtf | Rejected | Text path (rtf tag-stripped) | **New** |
| csv | Rejected | Capped text (~1MB / 500 rows), only the text stored | **New** |
| ics (file upload) | Accepted; sabre/vobject parse | Unchanged | Unchanged |
| **Audio** | | | |
| m4a / webm (voice notes) | Accepted; transcribed; audio kept forever | Transcribed; **audio deleted on approval**, transcript kept | Improved |
| mp3 / wav | Rejected | Accepted → same transcription pipeline | **New** |
| **Email** | | | |
| Forwarded email (dump address) | SES → parsed → attachments split → S3 object deleted | Attachments now recurse through all pipelines above | Improved |
| .eml (dragged from mail client) | Rejected | Parsed by the same MailMimeParser service as SES inbound; attachments recursed; raw .eml not stored | **New** |
| .msg (Outlook proprietary) | Rejected | Stays rejected — friendly "forward it to your dump address instead" | Rejected |
| **Other capture routes** | | | |
| Link / URL paste | readability.php → text | Unchanged | Unchanged |
| Manual text / dictation | Direct | Unchanged | Unchanged |
| Web voice recording | Doesn't exist | New capture UI riding the audio pipeline | **New** |
| zip / video | Rejected | Stays rejected (decided 2026-07-18) | Rejected |

## Pipeline A — Images

**Types in:** jpg, jpeg, png, webp, gif, heic, heif, tiff, avif, bmp
(allowlist gains `heif`, `tiff`, `avif`, `bmp`).

**Normaliser** (`app/Services/ImageNormalizer.php`, synchronous — Imagick does
this in well under a second):

1. `pingImage` first — reject anything over ~80 megapixels before decoding
   (decompression-bomb guard); set Imagick memory/map resource limits.
2. Decode → **auto-orient** (apply the EXIF orientation flag *before* we strip it,
   or every portrait iPhone photo comes out sideways).
3. Convert colourspace to sRGB (CMYK scans, weird profiles).
4. Resize to max **1600px** long edge (never upscale).
5. `stripImage()` — all EXIF/GPS/XMP/IPTC gone.
6. Encode **JPEG quality 80** → this becomes the stored file; attachment row
   updated (mime `image/jpeg`, new size, filename re-extensioned `.jpg`).

Animated GIFs: flatten to first frame (evidence, not memes). Transparency
(PNG logos etc.): composite onto white — JPEG has no alpha.

**Effects:** ~6MB HEIC → ~250KB JPEG (≈95% storage cut); the HEIC-unreadable-by-
vision-APIs bug disappears — the model only ever sees JPEG; GPS/location privacy
hole closed. iOS app converting HEIC→JPEG client-side stays as optional polish
(faster uploads), no longer a correctness requirement.

## Pipeline B — PDFs

Split on the existing scanned-vs-text detection (pdfparser extraction + the
`/Type /Page` regex):

**Text PDFs** (certificates generated digitally, letters): unchanged — extract
text with `smalot/pdfparser`, store the original (they're small). Add a size
sanity cap (~10MB) — a "text" PDF bigger than that is really a scan hybrid →
route to the scan path.

**Scanned/image PDFs** (photographed certificates, scanner output — routinely
10–30MB): **rasterise** instead of storing/sending the monster:

1. Imagick render at **150 DPI**, page cap stays at 20 (existing gate).
2. Each page through Pipeline A steps 3–6 (sRGB, 1600px, strip, JPEG q80).
3. Recombine pages into a **single compact PDF** (Imagick writes multi-page
   PDF from JPEGs) → that's the stored artefact — one file, opens anywhere,
   ~30MB in → ~1–2MB kept.
4. AI: send the page JPEGs directly as vision images (first N pages) instead of
   the raw PDF — cheaper, provider-agnostic, and removes the current
   "raw scan → model" input-token risk.

**Dependency:** Imagick PDF rendering shells out to **Ghostscript**, and
`queryFormats('PDF')` does not prove it's present (local Mac: listed as
supported, render fails — no `gs` binary). The probe command now attempts a
real render. **If production lacks Ghostscript:** scanned PDFs keep today's
behaviour (stored as-is, sent raw to the model, 20-page gate) — everything
else in this plan proceeds unchanged. Optional fallback: a static `gs` binary
shipped in the repo via `proc_open` (confirmed available), decided only if the
probe fails and the storage savings feel worth it.

## Pipeline C — Office documents (docx, pptx; legacy doc, ppt)

Office files are the awkward middle: no LibreOffice on Laravel Cloud (a real
`soffice` install is ~400MB — not happening in-app), so full visual conversion
to PDF isn't available without external compute. The plan:

**Keep:** phpoffice **text extraction** (built 2026-07-18) — slide/paragraph text
into `extracted_text`, feeds analysis and search.

**Add — embedded media extraction (pure PHP, no LibreOffice):** docx/pptx are
ZIP archives; images live at `word/media/*` and `ppt/media/*`. Open with
`ZipArchive`, pull embedded images, run each through **Pipeline A**, keep the
**10 largest ≥50KB** (skips bullet icons and logos), store alongside, and feed
the top few to the vision model with the extracted text. The AI finally *sees*
the graphs/photos in a teaching deck instead of only its prose. Slide layout is
still lost — flagged honestly in the draft ("slides' text + key images were
read; layout not preserved").

**Storage:** keep the original docx/pptx (it *is* the evidence and the user may
re-download it for appraisal) but cap at ~25MB; the extracted media JPEGs are
small. Full pptx→PDF conversion (LibreOffice Lambda / Gotenberg) stays on the
someday list — only worth it if users demand pixel-faithful slide archiving.

**Legacy .doc/.ppt** (pre-2007 OLE, not ZIP): phpoffice reads .doc tolerably,
.ppt poorly. Keep accepting both, extract what we can, and set the honesty flag
when extraction comes back thin — do not silently pretend we read them.

## Pipeline D — Audio (m4a, webm, mp3, wav — add mp3/wav to allowlist)

Unchanged transcription (OpenAI, works well). Lifecycle change per Tier-1
decision: **the audio file is deleted on approval** — the transcript is the
evidence and is already kept in the payload/extracted text. Pending items keep
their audio (user may want to re-listen while reviewing); dismiss already purges.

## Pipeline E — New types worth accepting

| Type | Handling |
|---|---|
| **.eml** (dragged out of a mail client) | Parse with the same MailMimeParser code path as SES inbound (`ProcessSesInboundEmail`'s parsing extracted into a shared service) — body → text, attachments recursively through these pipelines. Store nothing of the raw .eml. |
| **.csv** | Treat as text: cap ~1MB / first ~500 rows into `extracted_text`, store the capped text only (attendance exports, audit data). |
| **.xlsx** | Same idea via PhpSpreadsheet (already a phpoffice sibling): sheets → capped CSV-style text. Audit spreadsheets are common CPD evidence. Legacy .xls: accept, extract what PhpSpreadsheet manages, honesty-flag if thin. |
| **.md / .rtf** | Text path; rtf via simple tag-strip. |
| **.msg** (Outlook proprietary) | Skip for v1 — needs another parser dependency; the dump address covers the "it's an email" case. Reject with a friendly "forward it to your dump address instead". |
| **.zip / video** | Stay rejected (decided 2026-07-18). |

## User option — zero-retention mode (added 2026-07-19)

A per-user toggle in **Settings → Evidence**: *"Don't keep my files after AI
analysis."* Default **off** (normal lifecycle applies).

When on:

- Every attachment/upload is deleted from storage **as soon as analysis
  completes** (status → ready). The AI needs the file to analyse it, so
  deletion is post-analysis, not pre. Extracted text, transcripts, and the
  AI draft are kept — they're DB rows, not files, and they *are* the evidence
  trail.
- The attachment row survives as a **metadata stub** (`purged_at`, filename,
  size, mime) so the UI can say honestly: *"file discarded after analysis —
  your retention setting"* in the review modal and on activities, and the
  report zip export can list what's absent instead of silently shrinking.
- Failed analyses keep their file until the item is resolved (retry needs the
  bytes); resolution then purges as usual.
- Voice notes behave the same as everything else here (transcript kept, audio
  gone immediately rather than at approval).

**Settings copy must carry a real warning:** appraisal panels often want the
actual certificate. With this on, CPD Dump holds only text — the user is
choosing to keep originals themselves. Possible later refinement: a per-item
"keep this one" override at review time; not in v1 of this.

Toggle mid-stream: turning it **on** applies to new uploads only (no
retroactive purge in v1 — a separate explicit "delete all my stored files"
button could come later); turning it **off** starts keeping again from that
point.

## Cross-cutting (unchanged from Tier-1 agreement)

- **Mime sniffing** via `finfo` at ingest — extension renames can't smuggle types.
- **Per-user quota** ~500MB with a Settings usage meter.
- **Content dedupe** — the existing `content_hash` + attachment fingerprints
  already catch re-forwards; normalisation must hash the *original* bytes
  (pre-normalise) so a re-upload of the same HEIC still dedupes.
- **Honesty flags** — anything unreadable/thinly-read is flagged in the draft,
  never silently dropped (mechanism exists).
- Web voice notes (record in browser) — separate small feature, rides Pipeline D.

## Build order

| Step | What | Size | Depends on |
|---|---|---|---|
| 0 | ~~Re-run `cpd:check-image-support` on production~~ **done — Ghostscript ✔** | — | ✔ |
| 1 | **ImageNormalizer + wire into all ingest paths** (fixes HEIC bug, biggest win) | evening | — |
| 2 | Scanned-PDF rasteriser | short evening | step 0 says yes |
| 3 | Office embedded-media extraction | short evening | step 1 |
| 4 | New types (.eml, .csv, .md/.rtf) + allowlist/mime-sniff tidy | evening | step 1 |
| 5 | Audio lifecycle + per-user quota + usage meter + **zero-retention toggle** | evening | — |

Each step is independently shippable; step 1 alone resolves the only live bug.
