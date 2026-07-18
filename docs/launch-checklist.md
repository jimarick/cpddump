# CPD Dump — Launch checklist

Everything code-side is built; these are the account/DNS/env steps to go live.

## Laravel Cloud

- [ ] Create the app on Laravel Cloud, connected to the GitHub repo (push this repo to GitHub first).
- [ ] Provision Serverless Postgres; attach to the app (env injected automatically).
- [ ] Attach an object storage bucket (R2); set `FILESYSTEM_DISK=s3` in the environment.
- [ ] Enable a Managed Queue (default queue) — analysis, email processing and calendar sync all run through it.
- [ ] Scheduler is on by default — verify `cpd:generate-recurring` (daily 06:00), `cpd:send-weekly-reviews` (Mon 07:00) and `cpd:sync-calendars` (Sun 18:00) appear.
- [ ] Custom domain `cpddump.com` → point at Laravel Cloud per its DNS instructions (keep authoritative DNS at the registrar/Cloudflare).

## Environment variables (production)

- [ ] `APP_URL=https://cpddump.com`
- [ ] `AI_DEFAULT_PROVIDER=openai`, `OPENAI_API_KEY` (platform default AI + transcription), `ANTHROPIC_API_KEY` (optional fallback provider)
- [ ] Budgets (all default sensibly; tune later): `AI_DAILY_TOKEN_BUDGET` (200k out/user/day), `AI_DAILY_INPUT_TOKEN_BUDGET` (1M in/user/day), `AI_PLATFORM_DAILY_TOKEN_BUDGET` (10M/day global), `AI_MAX_SCANNED_PDF_PAGES` (20), `CPD_DAILY_ITEM_CAP` (40 items/user/day)
- [ ] Set hard spend limits in the OpenAI and Anthropic consoles (the code budgets' backstop)
- [ ] `RESEND_API_KEY` (outbound mail + inbound API) and `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS=hello@cpddump.com`
- [ ] `RESEND_INBOUND_WEBHOOK_SECRET` (from the webhook created below)
- [ ] `INBOUND_EMAIL_DOMAIN=cpddump.com` (root domain does sending *and* receiving — Resend basic plan allows one domain; replies to hello@ vanish into Resend inbound, so use a personal address for human contact)
- [ ] Paddle sandbox (scaffold only, nothing charged yet): `PADDLE_SANDBOX=true`, `PADDLE_SELLER_ID`, `PADDLE_API_KEY`, `PADDLE_CLIENT_SIDE_TOKEN`, `PADDLE_WEBHOOK_SECRET`

## Resend

- [ ] Verify `cpddump.com` as a sending domain (SPF + DKIM records).
- [ ] Enable **receiving** on `cpddump.com` (one-domain plan); add the root `@` MX record at DigitalOcean DNS.
- [ ] Create a webhook for `email.received` → `https://cpddump.com/webhooks/resend-inbound`; copy the signing secret into `RESEND_INBOUND_WEBHOOK_SECRET`.
- [ ] Send a test email to your own dump address end-to-end.

## First-run

- [ ] Deploy; migrations + `db:seed` (reference data seeder is idempotent) run via the deploy commands.
- [ ] Register your own account, then grant admin: `php artisan cpd:make-admin james.ricketts@gmail.com`.
- [ ] Walk the loop in production: upload a certificate → review → approve → timeline.
- [ ] Ask-a-question + full report generation.
- [ ] Connect a real ICS calendar feed and run `php artisan cpd:sync-calendars`.

## Before inviting beta users

- [ ] Review privacy policy + terms drafts (marked as drafts in the footer pages).
- [ ] Confirm daily AI budget + Resend quota headroom for expected user count.
- [ ] Backups: confirm Laravel Cloud Postgres backup policy.
- [ ] Rate limits are in place (inbox 30/min, AI assist 30/min, transcribe 20/min, API 60/min) — sanity-check under real use.

## Deferred by design (documented decisions)

- NHSmail calendar integration — needs per-organisation Local Administrator approval; ICS upload + invite-forwarding are the interim answers.
- Screenshot → multiple items splitting (backlog).
- Live streaming dictation (AssemblyAI) — current dictation is record-then-transcribe via OpenAI; upgrade if users want live text.
- Charging: Cashier Paddle is scaffolded (`User::isPremium()` returns true for everyone during beta).
