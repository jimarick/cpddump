import { Head, Link } from '@inertiajs/react';
import { BarChart3, Users } from 'lucide-react';

interface Props {
    stats: {
        users: number;
        onboarded: number;
        new_users_30d: number;
        activities: number;
        inbox_items_30d: number;
        ai_calls_30d: number;
        platform_output_tokens_30d: number;
        reports_30d: number;
    };
}

const n = (value: number) => Intl.NumberFormat('en-GB').format(value);

export default function AdminDashboard({ stats }: Props) {
    return (
        <>
            <Head title="Admin" />

            <div className="mb-6">
                <h1 className="font-display text-[32px] leading-none font-semibold tracking-[-0.01em]">Admin</h1>
                <p className="mt-1 text-[12.5px] font-semibold text-stone-500">The whole operation, at a glance.</p>
            </div>

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Users" value={n(stats.users)} sub={`${n(stats.onboarded)} onboarded · ${n(stats.new_users_30d)} new (30d)`} />
                <StatCard label="Activities" value={n(stats.activities)} sub="approved, all time" />
                <StatCard label="Items dumped (30d)" value={n(stats.inbox_items_30d)} sub={`${n(stats.reports_30d)} reports generated`} />
                <StatCard
                    label="AI calls (30d)"
                    value={n(stats.ai_calls_30d)}
                    sub={`${n(stats.platform_output_tokens_30d)} platform output tokens`}
                />
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2">
                <NavCard
                    href="/admin/users"
                    icon={<Users className="size-5 text-brand" />}
                    title="Users"
                    description="Everyone registered — activity counts, professions, flags, own-key status."
                    rotate={-0.4}
                />
                <NavCard
                    href="/admin/usage"
                    icon={<BarChart3 className="size-5 text-brand" />}
                    title="AI usage"
                    description="Daily token spend, calls by purpose, and the heaviest platform-key users."
                    rotate={0.4}
                />
            </div>
        </>
    );
}

function StatCard({ label, value, sub }: { label: string; value: string; sub: string }) {
    return (
        <div className="rounded-[12px] border-2 border-ink bg-white p-4 shadow-[4px_4px_0_rgba(28,25,23,.12)]">
            <div className="text-[10.5px] font-bold tracking-[0.08em] text-stone-400 uppercase">{label}</div>
            <div className="mt-1 font-display text-3xl font-semibold">{value}</div>
            <div className="mt-1 text-[11.5px] text-stone-500">{sub}</div>
        </div>
    );
}

function NavCard({
    href,
    icon,
    title,
    description,
    rotate,
}: {
    href: string;
    icon: React.ReactNode;
    title: string;
    description: string;
    rotate: number;
}) {
    return (
        <Link
            href={href}
            style={{ rotate: `${rotate}deg` }}
            className="block rounded-[14px] border-2 border-ink bg-white p-5 shadow-[5px_5px_0_rgba(28,25,23,.12)] transition-transform hover:-translate-y-0.5"
        >
            <div className="flex items-center gap-2.5">
                {icon}
                <span className="font-display text-xl font-semibold">{title}</span>
            </div>
            <p className="mt-1.5 text-[13px] text-stone-500">{description}</p>
        </Link>
    );
}
