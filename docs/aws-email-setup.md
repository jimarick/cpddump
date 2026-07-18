# AWS SES email setup — Phase 2 guide

Companion to the SES migration plan. Phase 1 (all code) ships first; this guide
covers the AWS-side setup. Region is **eu-west-2 (London)** everywhere — never
change it mid-setup.

## Path A — Agent-Toolkit-assisted (recommended)

Claude drives the AWS API calls; you do the human-only steps.

### 1. Create the AWS account (you, ~10 min)

- https://aws.amazon.com → Create account, using james.ricketts@gmail.com.
- Add the payment card (expected spend: pennies/month).
- Turn on MFA for the root user (Security credentials → Assign MFA).
- Sign in and set the console region to **Europe (London) eu-west-2**.

### 2. Install the Agent Toolkit (you, via `!` commands, ~5 min)

In the Claude Code prompt (the `!` prefix runs these outside the sandbox):

```
! curl -fsSL 'https://awscli.amazonaws.com/v2/install.sh' | bash
! aws login --region eu-west-2
! aws configure agent-toolkit --yes --region us-east-1
```

(The `us-east-1` on the last line is where the Agent Toolkit service itself
lives — it's the only region it supports. It does NOT affect where our
resources go; everything we build still lands in eu-west-2.)

`aws login` opens a browser — sign in with the account from step 1. The session
lasts 12 hours (renewable ~90 days). Then restart the Claude Code session so
the MCP server is picked up.

### 3. Claude builds the infrastructure (Claude, you watch)

Via the toolkit, Claude will:

- Create the S3 landing bucket (`cpddump-inbound-mail`): public access blocked,
  encryption on, lifecycle rule expiring objects after 3 days.
- Create the minimal IAM policy + user for the app (read/delete on that bucket,
  `ses:SendEmail`), and hand you the access key for Laravel Cloud env vars
  (`SES_INBOUND_KEY` / `SES_INBOUND_SECRET` / `SES_INBOUND_REGION` /
  `SES_INBOUND_BUCKET` — deliberately not `AWS_*`, which Laravel Cloud uses
  for R2).
- Verify `cpddump.com` in SES and output the DKIM CNAME records.
- Set up the custom MAIL FROM (`mail.cpddump.com`) and output its records.
- Create the receipt rule set (mail → S3 → SNS) scoped to the trial domain
  first, and the SNS topics (inbound + bounces/complaints).
- File the SES production-access request (needed for sending only; receiving
  works immediately).

### 4. DNS at DigitalOcean (you, ~10 min)

Paste the records Claude hands you:

- 3× DKIM CNAMEs (SES verification)
- 2× MAIL FROM records (MX + TXT on `mail.cpddump.com`)
- DMARC TXT: `_dmarc.cpddump.com` →
  `v=DMARC1; p=none; rua=mailto:dmarc@cpddump.com`
  (dmarc@ is an app inbound alias — reports relay to the contact email
  once SES inbound is live, keeping the personal address out of public DNS)
- Trial MX: `in-test.cpddump.com` → `10 inbound-smtp.eu-west-2.amazonaws.com`

These coexist with all existing Resend records. Nothing about production email
changes yet.

### 5. Wire the webhooks (ordering matters)

1. Deploy the app with the Phase 1 code FIRST (the SES webhook controller must
   be live).
2. Then Claude creates the SNS HTTPS subscriptions:
   - inbound topic → `https://cpddump.com/webhooks/ses-inbound`
   - bounce/complaint topic → `https://cpddump.com/webhooks/ses-events`
3. The app auto-confirms the subscriptions (watch the logs for the
   SubscriptionConfirmation lines).

### 6. Trial (Phase 3)

- Forward real emails — including PDF attachments — to
  `u_<your-token>@in-test.cpddump.com`; confirm they appear in the inbox and
  that the S3 objects are deleted after ingest.
- Send outbound tests through SES (`MAIL_MAILER=ses` on a test env): check
  headers at Gmail (SPF/DKIM/DMARC all pass + aligned), send one to your own
  nhs.net address, and run a mail-tester.com check.

### 7. Cutover (Phase 4)

- Widen the receipt rule to `cpddump.com`; swap the root MX from Resend to
  `10 inbound-smtp.eu-west-2.amazonaws.com`; set `MAIL_MAILER=ses` in
  production.
- Rollback at any point = revert the MX record / env var.
- After 2–4 weeks of clean DMARC reports: tighten to `p=quarantine`.
- Cancel Resend last.

## Path B — Manual console (fallback if the toolkit misbehaves)

Same steps as Path A section 3, done by hand in the AWS console; Claude
provides click-by-click instructions at the time. Order: S3 bucket → IAM →
SES verified identity → MAIL FROM → receipt rule set (the wizard creates the
bucket policy for you) → SNS topics → production-access form (SES console →
Account dashboard → Request production access).

## Reference

- Inbound endpoint (MX target): `inbound-smtp.eu-west-2.amazonaws.com`
- SES receiving is available in eu-west-2 (confirmed July 2026).
- Raw emails live in the bucket only until processed (seconds); the 3-day
  lifecycle rule is the failsafe for crashed jobs.
- Env vars the app needs: `SES_INBOUND_KEY`, `SES_INBOUND_SECRET`,
  `SES_INBOUND_REGION=eu-west-2`, `SES_INBOUND_BUCKET`, plus
  `MAIL_MAILER=ses` at cutover and `CPD_CONTACT_EMAIL` for the hello@ alias
  forward target.
