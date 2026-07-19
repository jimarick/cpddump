import { Head, Link } from '@inertiajs/react';

interface DailyRow {
    day: string;
    calls: number;
    input_tokens: number;
    output_tokens: number;
    platform_output_tokens: number;
}

interface PurposeRow {
    purpose: string;
    calls: number;
    output_tokens: number;
}

interface TopUserRow {
    user_id: number;
    calls: number;
    output_tokens: number;
    user: { id: number; name: string; email: string } | null;
}

interface Props {
    daily: DailyRow[];
    byPurpose: PurposeRow[];
    topUsers: TopUserRow[];
}

const n = (value: number) => Intl.NumberFormat('en-GB').format(value);

export default function AdminUsage({ daily, byPurpose, topUsers }: Props) {
    return (
        <>
            <Head title="Admin · AI usage" />

            <div className="mb-5 flex items-end justify-between">
                <div>
                    <h1 className="font-display text-[32px] leading-none font-extrabold tracking-[-0.03em]">
                        AI usage
                    </h1>
                    <p className="mt-1 text-[12.5px] font-semibold text-stone-500">
                        Last 30 days
                    </p>
                </div>
                <div className="flex gap-4">
                    <Link
                        href="/admin"
                        className="text-[13px] font-semibold text-stone-500 hover:text-ink"
                    >
                        ← Admin
                    </Link>
                    <Link
                        href="/admin/users"
                        className="text-[13px] font-semibold text-brand"
                    >
                        Users →
                    </Link>
                </div>
            </div>

            <div className="grid gap-5 lg:grid-cols-2">
                <Panel title="By purpose">
                    <Table
                        head={['Purpose', 'Calls', 'Output tokens']}
                        rows={byPurpose.map((r) => [
                            r.purpose,
                            n(r.calls),
                            n(r.output_tokens),
                        ])}
                    />
                </Panel>

                <Panel title="Heaviest platform-key users">
                    <Table
                        head={['User', 'Calls', 'Output tokens']}
                        rows={topUsers.map((r) => [
                            r.user
                                ? `${r.user.name} (${r.user.email})`
                                : `#${r.user_id}`,
                            n(r.calls),
                            n(r.output_tokens),
                        ])}
                    />
                </Panel>
            </div>

            <Panel title="Daily" className="mt-5">
                <Table
                    head={[
                        'Day',
                        'Calls',
                        'Input tokens',
                        'Output tokens',
                        'of which platform key',
                    ]}
                    rows={daily.map((r) => [
                        r.day,
                        n(r.calls),
                        n(r.input_tokens),
                        n(r.output_tokens),
                        n(r.platform_output_tokens),
                    ])}
                />
            </Panel>
        </>
    );
}

function Panel({
    title,
    children,
    className = '',
}: {
    title: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={`rounded-[14px] border-2 border-ink bg-white p-4 shadow-[5px_5px_0_rgba(28,25,23,.12)] ${className}`}
        >
            <h2 className="mb-3 font-display text-lg font-extrabold">
                {title}
            </h2>
            {children}
        </div>
    );
}

function Table({
    head,
    rows,
}: {
    head: string[];
    rows: (string | number)[][];
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-[13px]">
                <thead>
                    <tr className="border-b border-ink/10 text-left text-[10.5px] tracking-[0.08em] text-stone-400 uppercase">
                        {head.map((h) => (
                            <th key={h} className="px-2 py-2">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 ? (
                        <tr>
                            <td
                                colSpan={head.length}
                                className="px-2 py-4 text-center text-stone-400"
                            >
                                Nothing yet.
                            </td>
                        </tr>
                    ) : (
                        rows.map((row, i) => (
                            <tr
                                key={i}
                                className="border-b border-ink/5 last:border-0"
                            >
                                {row.map((cell, j) => (
                                    <td key={j} className="px-2 py-2">
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
        </div>
    );
}
