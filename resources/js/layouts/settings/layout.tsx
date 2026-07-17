import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

const NAV = [
    { title: 'Profile', href: '/settings/profile' },
    { title: 'Evidence & email', href: '/settings/evidence' },
    { title: 'Calendars', href: '/settings/calendars' },
    { title: 'AI', href: '/settings/ai' },
    { title: 'Security', href: '/settings/security' },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <>
            <div className="mb-5">
                <h1 className="font-display text-[32px] leading-none font-semibold tracking-[-0.01em]">
                    Settings
                </h1>
            </div>

            <nav
                className="mb-6 flex flex-wrap items-center gap-1.5"
                aria-label="Settings"
            >
                {NAV.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={cn(
                            'rounded-full px-3.5 py-1.5 text-[13px] font-semibold whitespace-nowrap transition-colors',
                            isCurrentOrParentUrl(item.href)
                                ? 'rotate-[-0.5deg] border-[1.5px] border-ink bg-brand-tint text-brand-dark'
                                : 'border-[1.5px] border-dashed border-stone-300 text-stone-500 hover:border-ink hover:text-ink',
                        )}
                    >
                        {item.title}
                    </Link>
                ))}
            </nav>

            <div className="max-w-2xl rounded-[14px] border-2 border-ink bg-white px-5 py-6 shadow-[5px_5px_0_rgba(28,25,23,.12)] md:px-7">
                {children}
            </div>
        </>
    );
}
