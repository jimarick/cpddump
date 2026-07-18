# CPD Dump — Launch checklist

Everything code-side is built; these are the account/DNS/env steps to go live.

## Laravel Cloud

- [ ] Create the app on Laravel Cloud, connected to the GitHub repo (push this repo to GitHub first).
- [ ] Provision Serverless Postgres; attach to the app (env injected automatically).
- [ ] Attach an object storage bucket (R2); set `FILESYSTEM_DISK=s3` in the environment.
- [ ] Enable a Managed Queue (default queue) — analysis, email processing and calendar sync all run through it.
- [ ] Scheduler is on by default — verify `cpd:send-weekly-reviews` (Mon 07:00) and `cpd:sync-calendars` (Sun 18:00) appear.
- [ ] Custom domain `cpddump.com` → point at Laravel Cloud per its DNS instructions (keep authoritative DNS at the registrar/Cloudflare).

## Environment variables (production)

- [ ] `APP_URL=https://cpddump.com`
- [ ] `ANTHROPIC_API_KEY` (platform default AI) and `OPENAI_API_KEY` (audio transcription)
- [ ] `AI_DAILY_TOKEN_BUDGET` — default 200k output tokens/user/day; tune later
- [ ] `RESEND_API_KEY` (outbound mail + inbound API) and `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS=hello@cpddump.com`
- [ ] `RESEND_INBOUND_WEBHOOK_SECRET` (from the webhook created below)
- [ ] `INBOUND_EMAIL_DOMAIN=in.cpddump.com`
- [ ] Paddle sandbox (scaffold only, nothing charged yet): `PADDLE_SANDBOX=true`, `PADDLE_SELLER_ID`, `PADDLE_API_KEY`, `PADDLE_CLIENT_SIDE_TOKEN`, `PADDLE_WEBHOOK_SECRET`

## Resend

- [ ] Verify `cpddump.com` as a sending domain (SPF + DKIM records).
- [ ] Add `in.cpddump.com` as a **receiving** domain; add its MX record at the DNS provider.
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
