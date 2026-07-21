import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Settings, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { Wordmark } from '@/components/brand/wordmark';
import { AppFooter } from '@/components/cpd/app-footer';
import { SearchCommand } from '@/components/cpd/search-command';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { useForceLight } from '@/hooks/use-force-light';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';

const TABS = [
    { label: 'Inbox', href: '/inbox' },
    { label: 'Timeline', href: '/timeline' },
    { label: 'Takeaways', href: '/takeaways' },
    { label: 'Projects', href: '/projects' },
    { label: 'Reports', href: '/reports' },
];

/** The signed-in app frame: brand top bar + tab row. No sidebar, ever. */
export default function AppShell({ children }: { children: ReactNode }) {
    useForceLight();
    useFlashToast();

    const page = usePage();
    const { auth } = page.props;
    const getInitials = useInitials();
    const currentPath = page.url;

    return (
        <div className="flex min-h-svh flex-col bg-paper font-sans text-ink">
            <header className="border-b border-dashed border-ink/18 bg-paper">
                <div className="mx-auto flex max-w-[1080px] items-center justify-between px-4 pt-4 pb-2 md:px-6">
                    <Link href="/inbox">
                        <Wordmark size="md" />
                    </Link>

                    <nav className="hidden items-center gap-1 md:flex">
                        {TABS.map((tab) => (
                            <TabLink
                                key={tab.href}
                                {...tab}
                                active={currentPath.startsWith(tab.href)}
                            />
                        ))}
                    </nav>

                    <div className="flex items-center gap-2.5">
                        <SearchCommand />
                        <DropdownMenu>
                            <DropdownMenuTrigger className="flex size-9 cursor-pointer items-center justify-center rounded-full border-2 border-ink bg-brand-tint text-[12px] font-bold text-brand-dark">
                                {getInitials(auth.user.name)}
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-48">
                                <div className="px-2 py-1.5 text-sm">
                                    <div className="font-semibold">
                                        {auth.user.name}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {auth.user.email}
                                    </div>
                                </div>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link
                                        href="/settings/profile"
                                        className="flex w-full items-center gap-2"
                                    >
                                        <Settings className="size-4" /> Settings
                                    </Link>
                                </DropdownMenuItem>
                                {auth.user.is_admin && (
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href="/admin"
                                            className="flex w-full items-center gap-2"
                                        >
                                            <ShieldCheck className="size-4" />{' '}
                                            Admin
                                        </Link>
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem
                                    onClick={() => router.post(logout().url)}
                                    className="flex items-center gap-2"
                                >
                                    <LogOut className="size-4" /> Sign out
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                <nav className="flex items-center gap-1 overflow-x-auto px-4 pb-2 md:hidden">
                    {TABS.map((tab) => (
                        <TabLink
                            key={tab.href}
                            {...tab}
                            active={currentPath.startsWith(tab.href)}
                        />
                    ))}
                </nav>
            </header>

            <main className="mx-auto w-full max-w-[1080px] flex-1 px-4 py-6 md:px-6">
                {children}
            </main>

            <AppFooter />
        </div>
    );
}

function TabLink({
    label,
    href,
    active,
}: {
    label: string;
    href: string;
    active: boolean;
}) {
    return (
        <Link
            href={href}
            prefetch
            cacheFor="30s"
            className={cn(
                'rounded-[9px] px-3 py-1.5 text-[13.5px] font-semibold whitespace-nowrap transition-colors',
                active
                    ? 'rotate-[-0.5deg] border-[1.5px] border-ink bg-white text-ink shadow-[2px_2px_0_rgba(28,25,23,.12)]'
                    : 'text-stone-500 hover:text-ink',
            )}
        >
            {label}
        </Link>
    );
}
