# CPD Dump — web app + backend (Laravel)

AI evidence inbox for UK doctors' appraisal. Laravel 13 + Inertia/React 19 +
Tailwind v4, live in production at cpddump.com on Laravel Cloud (auto-deploys
on every push to main — pushing IS deploying, so keep main green).

## Sibling app: iOS companion

**This backend has a native iOS companion app at `~/Code/cpd-dump-ios`**
(Swift/SwiftUI, Xcode project `CPDDump`, plus a `CPDDumpShare` share
extension). It talks to this backend exclusively through the Sanctum-token
API: `routes/api.php` → `app/Http/Controllers/Api/`. The API mirrors the web
inbox lifecycle: capture (text/voice/photo/file/link), list, review, approve
(with `keep_attachment_ids` + `pii_ack`), dismiss (hard delete), retry,
remove-pii, activities, stats.

Rules for keeping the two in step:

- **When planning any change**, consider whether it touches the API surface,
  the item/attachment lifecycle, upload handling, or auth — if so, the plan
  must state the iOS impact explicitly.
- **After making such a change**: if `~/Code/cpd-dump-ios` is an attached
  working directory in this session, apply the corresponding iOS changes
  directly. If it is not attached, end your summary with a clearly-marked
  **"iOS follow-up"** section listing exactly what the iOS session must
  change (endpoints, field names, status codes, behaviour), precise enough
  to paste into a Claude session in that repo.
- **Contract principles**: the server is the enforcement point (clients only
  surface rules like the PII gate); never break existing API response shapes
  without flagging it as a breaking change for the app; serialisers ship
  only what clients display (no raw source text).
- The reverse also holds: changes requested here that really belong in the
  iOS app should be called out rather than half-solved server-side.

## Working practices (hard-won specifics)

- Push via full URL (no named remote): `git push https://github.com/jimarick/cpddump.git main:main`.
- Tests run on Postgres, never SQLite: `vendor/bin/pest --parallel`. Also run
  `vendor/bin/phpstan analyse --memory-limit=1G`, `vendor/bin/pint --dirty`,
  and for frontend changes `npm run lint && npm run types:check && npm run build`.
- Laravel Cloud's queue is SQS — never prune Sqs from the AWS SDK
  (composer.json pre-autoload-dump), or every prod dispatch 500s.
- Email: AWS SES (eu-west-2), inbound via SNS webhooks; mail renders through
  the `cpd` markdown theme (`resources/views/vendor/mail/`) — emails are a
  brandable surface too, don't forget them in design changes.
- Local: Herd at https://cpd-dump.test; this Mac lacks Ghostscript and a HEIC
  encoder, so two pipeline tests skip locally but the paths are verified on
  production (`php artisan cpd:check-image-support`).
- Design language: Bricolage Grotesque display font, "cpd dump." wordmark
  with orange full stop (`resources/js/components/brand/wordmark.tsx`), paper
  + ink + one orange (#f4590c), hard offset shadows. The retired stamp logo
  and Cormorant serif must not reappear.
- Privacy is the product's spine: files normalise at ingest, source text is
  scrubbed after AI analysis, delete-by-default retention at approval, hard
  deletes, PII gate. `docs/file-pipeline-plan.md` is the canonical record —
  keep the privacy page's "What happens to your uploads" table in lockstep
  with any pipeline change.
- The public "How we use AI" page (`/ai`, `marketing/ai.tsx`) is the
  plain-English register of every AI scenario — any change to AI behaviour
  (new agent, new endpoint, changed rules like "reflections never written
  from nothing") must update that page in the same commit.
