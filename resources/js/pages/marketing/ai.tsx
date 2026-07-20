import { Sparkle } from '@/components/brand/sparkle';
import LegalLayout from '@/layouts/marketing/legal-layout';

/**
 * The plain-English register of every place AI touches a portfolio.
 * Lockstep rule (same as the privacy page's uploads table): any change
 * to AI behaviour must update this page in the same commit.
 */
export default function HowWeUseAi() {
    return (
        <LegalLayout title="How CPD Dump uses AI" updated="20 July 2026">
            <p>
                AI does the paperwork; it does not do your thinking. This page
                lists every place AI touches your portfolio — and the one place
                it deliberately holds back. It is written in scenarios, not
                model names, and we keep it current: when the product's AI
                behaviour changes, this page changes with it.
            </p>

            <section>
                <h2>When you dump evidence</h2>
                <p>
                    Forward an email, upload a certificate, share a photo or
                    talk into a voice note, and AI reads it to draft the
                    portfolio entry for you: a title, the date, an estimate of
                    CPD points, a first-person summary of what the activity
                    was, and a suggested categorisation against your
                    profession's framework. It also flags anything that looks
                    like patient-identifiable information so you can deal with
                    it before the entry is saved, and spots when two dumps look
                    like the same event so you can combine them.
                </p>
            </section>

            <section>
                <h2>Your reflections — never written from nothing</h2>
                <div className="rounded-[10px] border-2 border-dashed border-brand bg-brand-pale p-4">
                    <p className="flex items-start gap-2 font-semibold text-ink">
                        <Sparkle
                            size={13}
                            className="mt-1 shrink-0 text-brand"
                        />
                        <span>
                            Every reflection in your portfolio started with
                            you. AI never invents one.
                        </span>
                    </p>
                    <p className="mt-2">
                        If your own words contain reflection — a comment you
                        typed above a forwarded email, musings in a voice note
                        — AI shapes <em>those words</em> into your profession's
                        reflection answers, and fills only the answers your
                        words actually support. If your evidence is purely
                        factual (a certificate, an agenda, "there was an MDT on
                        Tuesday"), the reflection boxes stay empty and the app
                        invites you to talk your reflection through in your own
                        words first. The "shape into reflections" button then
                        tidies your ramble into well-formed answers — tidying,
                        never replacing.
                    </p>
                </div>
            </section>

            <section>
                <h2>The sparkle button</h2>
                <p>
                    Any text box with the ✦ sparkle can polish what you wrote
                    into flowing prose — keeping your meaning and your
                    specifics, never adding facts you didn't give it. One tap
                    undoes it.
                </p>
            </section>

            <section>
                <h2>Dictation</h2>
                <p>
                    Voice notes and the in-app microphone are transcribed by AI
                    into text you can read and edit before anything else
                    happens with it. The transcript is yours to correct — the
                    audio is handled under the retention rules on the privacy
                    page.
                </p>
            </section>

            <section>
                <h2>Merging evidence</h2>
                <p>
                    When several dumps turn out to be the same event — an
                    email, a certificate and a voice note about one course —
                    AI drafts the combined entry: one summary and one woven
                    answer per reflection question, drawing only on what the
                    sources contain. Anything you wrote yourself takes
                    precedence over AI drafts.
                </p>
            </section>

            <section>
                <h2>Reports and appraisal answers</h2>
                <p>
                    At appraisal time, AI can draft your appraisal-period
                    report and answer appraisal-form questions in your voice —
                    grounded only in the activities and reflections you have
                    actually evidenced. If it isn't in your portfolio, it
                    doesn't go in the report.
                </p>
            </section>

            <section>
                <h2>What we keep</h2>
                <p>
                    AI processing changes nothing about retention: what we
                    store, for how long, and what gets scrubbed after analysis
                    is set out in the{' '}
                    <a
                        href="/privacy"
                        className="font-semibold text-ink underline underline-offset-3"
                    >
                        privacy policy
                    </a>{' '}
                    — see "What happens to your uploads". Questions:
                    hello@cpddump.com.
                </p>
            </section>
        </LegalLayout>
    );
}
