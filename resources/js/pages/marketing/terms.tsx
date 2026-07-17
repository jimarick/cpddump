import LegalLayout from '@/layouts/marketing/legal-layout';

export default function Terms() {
    return (
        <LegalLayout
            title="Terms of service"
            updated="17 July 2026 (draft — for review before launch)"
        >
            <p>
                These terms govern your use of CPD Dump. By creating an account
                you agree to them. CPD Dump is currently in beta: things may
                change, break, or improve without notice.
            </p>

            <section>
                <h2>What CPD Dump is (and isn't)</h2>
                <p>
                    CPD Dump is a personal tool for collecting, organising and
                    summarising your own professional development evidence. It
                    is not medical software, does not provide clinical or
                    professional advice, and does not replace your professional
                    judgement or your obligations to your regulator, employer or
                    appraiser. You are responsible for the accuracy of anything
                    you submit to an appraisal or revalidation process.
                </p>
            </section>

            <section>
                <h2>Your responsibilities</h2>
                <ul>
                    <li>
                        <strong>
                            Never upload patient-identifiable information
                        </strong>
                        , or information identifying colleagues or other third
                        parties. This is a condition of use.
                    </li>
                    <li>
                        Only send us content you have the right to store and
                        process.
                    </li>
                    <li>
                        Keep your account credentials, dump address and API keys
                        secure.
                    </li>
                    <li>
                        Review AI-generated drafts before approving them — AI
                        can be wrong, and the approved record is yours.
                    </li>
                </ul>
            </section>

            <section>
                <h2>AI-generated content</h2>
                <p>
                    AI suggestions (titles, points, categorisations,
                    reflections, summaries) are drafts produced by third-party
                    language models. They may contain errors. Nothing becomes
                    part of your portfolio until you approve it, and you are
                    responsible for what you approve.
                </p>
            </section>

            <section>
                <h2>Your content</h2>
                <p>
                    You retain all rights to the evidence you store in CPD Dump.
                    You grant us the limited licence needed to store, process
                    and display it to you, including passing it to the AI
                    provider you have chosen for analysis. We do not use your
                    content to train models or sell it to anyone.
                </p>
            </section>

            <section>
                <h2>The beta service</h2>
                <p>
                    The service is provided "as is" during beta, free of charge,
                    without warranties of availability or fitness for a
                    particular purpose. Export your data regularly. To the
                    maximum extent permitted by law, our liability is limited to
                    the amount you have paid us in the preceding 12 months.
                </p>
            </section>

            <section>
                <h2>Ending things</h2>
                <p>
                    You can delete your account at any time. We may suspend
                    accounts that break these terms — in particular the
                    prohibition on patient-identifiable data. These terms are
                    governed by the law of England and Wales.
                </p>
            </section>

            <section>
                <h2>Contact</h2>
                <p>Questions about these terms: hello@cpddump.com.</p>
            </section>
        </LegalLayout>
    );
}
