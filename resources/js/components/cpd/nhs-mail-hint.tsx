/** Gentle steer away from nhs.net as the account address — its filters eat external mail. */
export function NhsMailHint({ email }: { email: string }) {
    if (!email.toLowerCase().trim().endsWith('@nhs.net')) {
        return null;
    }

    return (
        <p className="text-[12px] leading-snug text-amber-700">
            Heads-up: NHS mail filters external email aggressively, so weekly
            summaries may not reach an nhs.net inbox. A personal address is more
            reliable — forwarding evidence <em>from</em> nhs.net works perfectly
            either way.
        </p>
    );
}
