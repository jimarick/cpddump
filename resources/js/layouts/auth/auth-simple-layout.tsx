import { Link } from '@inertiajs/react';
import { StampLogo } from '@/components/brand/stamp-logo';
import { useForceLight } from '@/hooks/use-force-light';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const gridBg = {
    backgroundImage:
        'linear-gradient(rgba(28,25,23,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(28,25,23,.045) 1px,transparent 1px)',
    backgroundSize: '52px 52px',
};

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    useForceLight();

    return (
        <div
            className="flex min-h-svh flex-col items-center justify-center gap-6 bg-paper p-6 font-sans text-ink md:p-10"
            style={gridBg}
        >
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-7">
                    <Link
                        href={home()}
                        className="flex -rotate-1 justify-center"
                    >
                        <StampLogo size="lg" />
                        <span className="sr-only">CPD Dump</span>
                    </Link>

                    <div className="-rotate-[0.6deg] rounded-[14px] border-2 border-ink bg-white px-6 py-7 shadow-[6px_6px_0_rgba(28,25,23,.12)] md:px-7">
                        <div className="mb-6 space-y-1.5 text-center">
                            <h1 className="font-display text-[28px] leading-[1.1] font-semibold tracking-[-0.01em]">
                                {title}
                            </h1>
                            <p className="text-[13.5px] text-pretty text-stone-500">
                                {description}
                            </p>
                        </div>
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
