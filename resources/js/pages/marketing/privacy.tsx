import LegalLayout from '@/layouts/marketing/legal-layout';

export default function Privacy() {
    return (
        <LegalLayout
            title="Privacy policy"
            updated="17 July 2026 (draft — for review before launch)"
        >
            <p>
                CPD Dump helps healthcare professionals collect and organise
                their own continuing professional development (CPD) evidence.
                This policy explains what we store, why, and the rules that keep
                patients and colleagues out of it.
            </p>

            <section>
                <h2>The golden rule: no patient data</h2>
                <p>
                    CPD Dump is for evidence about{' '}
                    <em>your professional development</em> — never about
                    patients. Nothing you upload, forward, dictate or paste
                    should identify a patient, a colleague, or any other third
                    party. We scan incoming content for likely identifiers
                    (names, NHS numbers, dates of birth, addresses) and warn you
                    before anything is stored, but the responsibility for
                    anonymising content remains yours. If we identify content
                    that appears to contain patient-identifiable information, we
                    will flag it for your review and you should delete or edit
                    it.
                </p>
            </section>

            <section>
                <h2>What we collect</h2>
                <ul>
                    <li>
                        Your account details: name, email address, profession,
                        and appraisal period dates.
                    </li>
                    <li>
                        Evidence you choose to send us: forwarded emails,
                        uploaded files (PDFs, photos, screenshots), pasted
                        links, voice notes and their transcripts, calendar
                        events from feeds you connect, and text you type.
                    </li>
                    <li>
                        AI-generated drafts (titles, summaries, categorisations,
                        reflections) that you review and approve.
                    </li>
                    <li>Basic usage and billing records.</li>
                </ul>
            </section>

            <section>
                <h2>How AI processing works</h2>
                <p>
                    When you send evidence to your inbox, we pass its content to
                    an AI provider (Anthropic or OpenAI) to extract titles,
                    dates, CPD points and suggested categorisations, and to
                    draft reflections. If you supply your own API key, your
                    content is processed under your own agreement with that
                    provider. Nothing an AI drafts becomes part of your
                    portfolio until you approve it.
                </p>
            </section>

            <section>
                <h2>Emails you forward</h2>
                <p>
                    Your personal dump address exists only to receive evidence
                    from you. We extract the useful content and attachments, and
                    minimise the raw email we retain. Do not forward emails
                    containing patient-identifiable information.
                </p>
            </section>

            <section>
                <h2>Calendar feeds</h2>
                <p>
                    If you connect a calendar feed URL, we read event titles,
                    times and organisers on a weekly schedule to suggest draft
                    activities. Feed URLs are stored encrypted and are never
                    shared. You can disconnect a feed at any time, and you can
                    tell us to permanently ignore particular recurring events.
                </p>
            </section>

            <section>
                <h2>Storage and security</h2>
                <ul>
                    <li>
                        Uploads are stored encrypted at rest with a reputable
                        cloud provider.
                    </li>
                    <li>
                        API keys and calendar feed URLs you supply are stored
                        encrypted.
                    </li>
                    <li>
                        Access to your evidence is restricted to your account.
                    </li>
                </ul>
            </section>

            <section>
                <h2>Your rights</h2>
                <p>
                    Your evidence is yours. You can export it, and you can
                    delete individual items or your whole account at any time —
                    deletion removes your evidence from our systems. Under UK
                    GDPR you also have rights of access, rectification and
                    portability; contact us at privacy@cpddump.com to exercise
                    them or to complain (you may also complain to the ICO).
                </p>
            </section>
        </LegalLayout>
    );
}
