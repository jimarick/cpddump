import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Wordmark } from '@/components/brand/wordmark';
import { useForceLight } from '@/hooks/use-force-light';
import { home } from '@/routes';

interface LegalLayoutProps {
    title: string;
    updated: string;
    children: ReactNode;
}

/** Shared shell for legal/static marketing pages (privacy, terms). */
export default function LegalLayout({
    title,
    updated,
    children,
}: LegalLayoutProps) {
    useForceLight();

    return (
        <>
            <Head title={title} />
            <div className="min-h-screen bg-paper font-sans text-ink">
                <div className="flex items-center justify-between border-b border-dashed border-ink/18 px-5 py-[18px] md:px-12">
                    <Link href={home()}>
                        <Wordmark size="md" />
                    </Link>
                    <Link
                        href={home()}
                        className="text-[13.5px] font-semibold text-stone-600 hover:text-ink"
                    >
                        ← Back to CPD Dump
                    </Link>
                </div>
                <div className="mx-auto max-w-[720px] px-5 py-14 md:px-0">
                    <h1 className="font-display text-[44px] leading-[1.05] font-extrabold tracking-[-0.03em]">
                        {title}
                    </h1>
                    <p className="mt-2 text-[13px] text-stone-500">
                        Last updated: {updated}
                    </p>
                    <div className="legal-prose mt-8 flex flex-col gap-6 text-[15px] leading-[1.65] text-stone-600 [&_h2]:font-sans [&_h2]:text-[19px] [&_h2]:font-bold [&_h2]:tracking-[-0.01em] [&_h2]:text-ink [&_li]:mt-1.5 [&_ul]:list-disc [&_ul]:pl-5">
                        {children}
                    </div>
                </div>
            </div>
        </>
    );
}
