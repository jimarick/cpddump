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
                <h2>What happens to your uploads</h2>
                <p>
                    <strong>"Your drafted entry"</strong> means the appraisal
                    entry our AI writes from your evidence — the title, dates,
                    CPD details and reflection you review and approve. It is
                    written <em>without</em> any names, NHS numbers or other
                    identifying details, even if they appeared in what you
                    uploaded. For most upload types, this drafted entry is the
                    only thing we keep — the original recording, email or data
                    is deleted once it has been read.
                </p>
                <div style={{ overflowX: 'auto' }}>
                    <table>
                        <thead>
                            <tr>
                                <th>You give us</th>
                                <th>What we do with it</th>
                                <th>Stored while you review?</th>
                                <th>Stored after you approve?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>A photo or screenshot</td>
                                <td>
                                    We shrink it, convert it to a standard JPEG
                                    and remove all hidden data (including
                                    location) before saving anything
                                </td>
                                <td>
                                    Yes — the cleaned copy, until you approve or
                                    bin it
                                </td>
                                <td>
                                    Only if you tick "keep this file" —
                                    otherwise deleted the moment you approve
                                </td>
                            </tr>
                            <tr>
                                <td>A PDF certificate</td>
                                <td>
                                    Small PDFs kept as-is; big scans are rebuilt
                                    as compact copies
                                </td>
                                <td>Yes, until you approve or bin it</td>
                                <td>Only if you tick "keep this file"</td>
                            </tr>
                            <tr>
                                <td>A PowerPoint or Word document</td>
                                <td>
                                    We read the text and key images out of it
                                </td>
                                <td>Yes, until you approve or bin it</td>
                                <td>Only if you tick "keep this file"</td>
                            </tr>
                            <tr>
                                <td>A spreadsheet (Excel/CSV)</td>
                                <td>
                                    We read the data, write your drafted entry
                                    (the AI analysis), then delete our copy of
                                    the data — the file itself is never saved
                                </td>
                                <td>No — already gone once read</td>
                                <td>No — only your drafted entry remains</td>
                            </tr>
                            <tr>
                                <td>A voice note</td>
                                <td>
                                    We transcribe it, delete the recording
                                    immediately, write your drafted entry, then
                                    delete the transcript too
                                </td>
                                <td>No — already gone once read</td>
                                <td>No — only your drafted entry remains</td>
                            </tr>
                            <tr>
                                <td>A forwarded email</td>
                                <td>
                                    We delete the original within seconds of it
                                    arriving, and delete its text as soon as
                                    it's been read and your entry drafted
                                </td>
                                <td>
                                    No — already gone once read (attachments
                                    follow their own rows above)
                                </td>
                                <td>No — only your drafted entry remains</td>
                            </tr>
                            <tr>
                                <td>A link</td>
                                <td>
                                    We read the page's text to write your
                                    drafted entry, then delete it; the page is
                                    never stored
                                </td>
                                <td>No — already gone once read</td>
                                <td>No — only your drafted entry remains</td>
                            </tr>
                            <tr>
                                <td>Entries you combine (merge)</td>
                                <td>
                                    The original entries and their files are
                                    kept underneath the combined entry, exactly
                                    as they were — un-combining restores them.
                                    The patient-information check still applies
                                    before anything can be combined
                                </td>
                                <td>—</td>
                                <td>
                                    Yes, hidden inside the combined entry.
                                    Deleting a combined entry permanently
                                    deletes everything inside it, including
                                    files
                                </td>
                            </tr>
                            <tr>
                                <td>Anything you bin</td>
                                <td>—</td>
                                <td>—</td>
                                <td>
                                    Deleted immediately, including any files
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p>
                    We automatically scan everything for patient-identifiable
                    information (like NHS numbers) and will stop you approving
                    an item until you've removed it or confirmed it's safe.
                    Files are never kept unless you explicitly choose to keep
                    them — and you can switch to "never keep files" in Settings
                    → Evidence, so CPD Dump stores nothing but your written
                    entries.
                </p>
            </section>

            <section>
                <h2>Emails you forward</h2>
                <p>
                    Your personal dump address exists only to receive evidence
                    from you. The raw email is deleted within seconds of
                    arriving — we keep only what the table above describes. Do
                    not forward emails containing patient-identifiable
                    information.
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
