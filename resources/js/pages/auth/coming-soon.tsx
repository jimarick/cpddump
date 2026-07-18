import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

export default function ComingSoon() {
    return (
        <>
            <Head title="Coming soon" />

            <div className="flex flex-col items-center gap-6 text-center">
                <p className="text-sm text-stone-600">
                    We're letting people in a few at a time while the AI filing
                    beds in. Leave it with us — the pile will be ready for you
                    soon.
                </p>

                <p className="font-hand text-xl text-brand-dark">
                    small pile now, easy appraisal later.
                </p>

                <Button
                    asChild
                    className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                >
                    <Link href={login()}>Already have an account? Log in</Link>
                </Button>
            </div>
        </>
    );
}

ComingSoon.layout = {
    title: 'Coming soon',
    description: 'CPD Dump is in private beta — new sign-ups open shortly',
};
