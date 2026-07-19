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

Unchanged transcription (OpenAI, works well). Lifecycle (revised 2026-07-19,
stricter than the original "delete on approval"): **the audio file is deleted
immediately after successful transcription**, and **the transcript is scrubbed
immediately after successful analysis** — the AI reads it, drafts the
reflection, and the raw transcript goes. What the user reviews and keeps is
the draft. Failed transcription/analysis keeps file/transcript for retry until
the item is resolved. Audio is never offered for keeping.

## Pipeline E — New types worth accepting

| Type | Handling |
|---|---|
| **.eml** (dragged out of a mail client) | Parse with the same MailMimeParser code path as SES inbound (`ProcessSesInboundEmail`'s parsing extracted into a shared service) — body → text, attachments recursively through these pipelines. Store nothing of the raw .eml. |
| **.csv** | Treat as text: cap ~1MB / first ~500 rows into `extracted_text`, store the capped text only (attendance exports, audit data). |
| **.xlsx** | Same idea via PhpSpreadsheet (already a phpoffice sibling): sheets → capped CSV-style text. Audit spreadsheets are common CPD evidence. Legacy .xls: accept, extract what PhpSpreadsheet manages, honesty-flag if thin. |
| **.md / .rtf** | Text path; rtf via simple tag-strip. |
| **.msg** (Outlook proprietary) | Skip for v1 — needs another parser dependency; the dump address covers the "it's an email" case. Reject with a friendly "forward it to your dump address instead". |
| **.zip / video** | Stay rejected (decided 2026-07-18). |

## Retention model — delete by default, keep by choice (revised 2026-07-19)

**Nothing survives resolution unless the user explicitly asks.** This replaces
the earlier "zero-retention toggle on an otherwise keep-everything default" —
the polarity is now flipped.

- **During review** (pending/ready): the file remains in storage so the user
  can view what they're approving.
- **At approval**, for keepable types only — **PDFs, office documents,
  images** — the review modal asks: *"Keep this file with your activity?"*
  with the warning *"only keep it if you're sure it contains no personal or
  sensitive information."* **Default: No.** Unkept files are purged at
  approval; extracted text and the drafted reflection remain (DB rows, the
  actual evidence trail).
- **Never offered for keeping** (deleted before the question can arise):
  audio (gone straight after transcription — Pipeline D), raw emails (S3
  object deleted at ingest, body redacted at resolve), spreadsheets/text
  types (never stored as files at all).
- **Dismiss** purges everything, as today.
- Unkept/purged attachments leave a **metadata stub** (`purged_at`, filename,
  size, mime) so activities and the report zip can honestly show *"file not
  kept"* instead of silently shrinking.
- **Two clocks — files vs text (revised again 2026-07-19: text dies at
  analysis, not resolve).** Verified: the review UI never displays raw
  source text — the modal shows the AI draft + attachment files, and the
  frontend reads only title/subject/url from the payload. So there is no
  review-time reason to keep it. Free-floating source text (email body/html,
  transcript, spreadsheet rows, fetched page text) is **scrubbed immediately
  after successful analysis** — before the user even reviews. It survives
  only on *failed* items (retry needs the source), purging at resolve as
  today. Text extracted from a stored file (PDF/office/image) follows its
  file's fate instead: kept file → text kept (content search), unkept/purged
  file → text gone. Manual/dictated entries are untouched — that text is the
  user's own authored evidence, not third-party source material.
- **Serialisation trim (found during this review):** the inbox controller
  currently sends the whole `raw_payload` to the browser though the UI uses
  only title/subject/url — trim to exactly those fields.
- **PII gate composes with this:** a scan- or AI-flagged file can only be
  kept via the explicit *"Keep — I've checked"* affirmation.

**Settings refinements** (Settings → Evidence), for users who don't want the
per-file question:

- *Always keep my files, don't ask* — old behaviour, for those who want CPD
  Dump to hold their originals for appraisal.
- *Never keep files, don't ask* — zero-retention; the question is skipped and
  everything purges at analysis-complete.

No retroactive changes when settings flip — applies to new items only; a
separate explicit "delete all my stored files" button is a possible later
addition.

## Data protection — accidental patient data (added 2026-07-19)

Threat model: a user uploads or email-forwards something that unexpectedly
contains patient-identifiable data (PID) — a screenshot with a PACS banner, an
audit spreadsheet with patient rows, a quoted email thread, an attachment they
didn't check. Under UK GDPR that's special-category data we never wanted.

**Already in place (verified in code):**

- The analyst prompt flags identifiers (`pii_flags`: type, excerpt, severity)
  and is instructed to *never reproduce identifiers in drafted text — write
  around them* (`InboxAnalystAgent`). `PiiWarningBanner` surfaces flags in
  the review modal.
- Dismiss = full purge: file deleted AND attachment row (with
  `extracted_text`) deleted, payload redacted. SES mail objects delete after
  ingest. EXIF/GPS stripping and zero-retention mode are in this plan.

**Gaps → new measures:**

1. **Deterministic PID pre-scan (no AI dependency).** Regex + checksum scan of
   all extracted text at ingest: NHS numbers (10-digit mod-11 — near-zero
   false positives), DOB patterns, postcode-plus-name proximity. Results merge
   into the same `pii_flags` shape (`detected_by: scanner`). Catches PID even
   when the model misses it or analysis fails, and costs nothing.
2. **Active PII gate — but only when a decision actually exists.** The gate
   blocks approve ONLY when something persistent still holds the flagged
   content:
   - **A stored file** (image/PDF/office attachment) → choose **"Remove
     patient info"** (purge file + its extracted text) or **"Keep — I've
     checked"** (affirmation stored).
   - **User-authored text** (manual/dictated entry) → choose scrub excerpts
     or keep-affirmed.
   - **The AI draft itself** — belt-and-braces: the deterministic scanner
     re-runs on draft fields; a hard hit there (e.g. an NHS number the model
     copied despite instructions) is **auto-scrubbed**, with a notice.
   Flags whose source was *already-deleted* text (email body, transcript,
   spreadsheet rows, page text) get **no decision prompt** — just an
   informational note on the item: *"patient information was detected in the
   source and has already been deleted."* Asking the user to delete what no
   longer exists would train them to ignore the gate.
3. **We currently store the identifier ourselves — fix it.** `pii_flags`
   excerpts live in `ai_analysis`, which survives resolve/dismiss "for dedupe
   and audit". On resolve, excerpts are redacted down to type + count only.
4. **Delete-by-default retention** (see Retention model above): keeping a
   file is now an explicit per-file opt-in at approval, with a
   check-for-sensitive-information warning — accidental PID in a kept file
   requires the user to have actively said "keep" past two prompts.
5. **High-risk types never store originals.** csv/xlsx (the classic PID
   vector: audit data) already store capped text only under this plan — the
   PID scan runs on that text before it's written.
6. **Post-approval remedy.** The same "remove patient info" action on an
   Activity (user notices weeks later): purge file + scrub, keep the clean
   reflection. Activity delete already removes files.

### What "Remove patient info" actually does, per type

The rule everywhere: **delete every copy of the source content we hold (file,
extracted text, payload text) and keep only the AI draft** — which the prompt
already requires to be written *around* identifiers. As belt-and-braces, the
deterministic scanner re-runs over the draft fields (title, summary,
reflection) before the item is considered clean; any hit there is scrubbed too.

| What we hold | One click removes |
|---|---|
| Image (normalised JPEG) | The image file (PID is in the pixels — can't be partially scrubbed) + metadata stub notes why |
| Digital PDF | The PDF file + its extracted text |
| Scanned PDF (compact rebuild) | The rebuilt PDF file |
| Office doc | The original file + extracted embedded images + extracted text |
| Spreadsheet / csv | Nothing — already deleted at analysis → **no prompt**, informational note only |
| Email | Body already gone → no prompt for it; flagged **attachments** get the file prompt per rows above |
| Audio | Nothing — audio died at transcription, transcript at analysis → **no prompt**, note only |
| Manual text / dictation | Prompt applies: scrub the flagged excerpts from the user's typed text, or keep-affirmed |
| Link | Nothing — page text already gone → **no prompt**, note only |

In every case what survives is: the drafted, identifier-free reflection and
the item's metadata. The user's evidence trail continues; the patient data
does not.

### Privacy page — "What happens to your uploads" (new deliverable)

A new section on the public privacy page: a plain-English table, one row per
upload type, kept in lockstep with this pipeline (update the page whenever the
pipeline changes). Draft copy:

| You give us | What we do with it | Stored while you review? | Stored after you approve? |
|---|---|---|---|
| A photo or screenshot | We shrink it, convert it to a standard JPEG and remove all hidden data (including location) before saving anything | Yes — the cleaned copy, until you approve or bin it | Only if you tick "keep this file" — otherwise deleted the moment you approve |
| A PDF certificate | Small PDFs kept as-is; big scans are rebuilt as compact copies | Yes, until you approve or bin it | Only if you tick "keep this file" |
| A PowerPoint or Word document | We read the text and key images out of it | Yes, until you approve or bin it | Only if you tick "keep this file" |
| A spreadsheet (Excel/CSV) | We read the data, draft your entry, then **delete our copy of the data** — the file itself is never saved | No — already gone once read | No — your drafted entry remains |
| A voice note | We transcribe it, **delete the recording immediately**, draft your entry, then delete the transcript too | No — already gone once read | No — your drafted entry remains |
| A forwarded email | We **delete the original within seconds of it arriving**, and delete its text as soon as it's been read | No — already gone once read (attachments follow their own rows) | No — your drafted entry remains |
| A link | We read the page's text to draft your entry, then delete it; the page is never stored | No — already gone once read | No — your drafted entry remains |
| Anything you bin | — | — | Deleted immediately, including any files |

Plus two sentences on the PID machinery: "We automatically scan everything
for patient-identifiable information (like NHS numbers) and will stop you
approving an item until you've removed it or confirmed it's safe. Files are
never kept unless you explicitly choose to keep them."

Provider note: OpenAI/Anthropic API inputs aren't used for training and are
retained ~30 days for abuse monitoring; the file itself never goes to a
provider unless it's analysable (images/PDFs), and under this plan what goes
is the normalised, smaller artefact.

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
| 5 | **Delete-by-default retention** (keep-file prompt at approval, settings overrides) + audio delete-post-transcription + per-user quota + usage meter | evening | — |
| 6 | **PID defence pack**: deterministic scanner, PII approve-gate + per-type one-click scrub, flag-excerpt redaction on resolve, post-approval remedy | evening | — |
| 7 | **Privacy page**: "What happens to your uploads" plain-English table (copy drafted above) | short | steps 1–6 shipped |

Each step is independently shippable; step 1 alone resolves the only live bug.
