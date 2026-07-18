import { Head, Link } from '@inertiajs/react';

interface AdminUser {
    id: number;
    name: string;
    email: string;
    profession: string | null;
    onboarded: boolean;
    is_admin: boolean;
    own_key: boolean;
    activities_count: number;
    inbox_items_count: number;
    created_at: string;
}

interface Props {
    users: {
        data: AdminUser[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
}

export default function AdminUsers({ users }: Props) {
    return (
        <>
            <Head title="Admin · Users" />

            <div className="mb-5 flex items-end justify-between">
                <div>
                    <h1 className="font-display text-[32px] leading-none font-semibold tracking-[-0.01em]">
                        Users
                    </h1>
                    <p className="mt-1 text-[12.5px] font-semibold text-stone-500">
                        {users.total} registered
                    </p>
                </div>
                <Link
                    href="/admin/usage"
                    className="text-[13px] font-semibold text-brand"
                >
                    AI usage →
                </Link>
            </div>

            <div className="overflow-x-auto rounded-[14px] border-2 border-ink bg-white shadow-[6px_6px_0_rgba(28,25,23,.12)]">
                <table className="w-full text-[13px]">
                    <thead>
                        <tr className="border-b border-ink/10 text-left text-[10.5px] tracking-[0.08em] text-stone-400 uppercase">
                            <th className="px-4 py-2.5">User</th>
                            <th className="px-4 py-2.5">Profession</th>
                            <th className="px-4 py-2.5">Activities</th>
                            <th className="px-4 py-2.5">Inbox</th>
                            <th className="px-4 py-2.5">Flags</th>
                            <th className="px-4 py-2.5">Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        {users.data.map((user) => (
                            <tr
                                key={user.id}
                                className="border-b border-ink/5 last:border-0"
                            >
                                <td className="px-4 py-2.5">
                                    <span className="block font-semibold">
                                        {user.name}
                                    </span>
                                    <span className="text-xs text-stone-500">
                                        {user.email}
                                    </span>
                                </td>
                                <td className="px-4 py-2.5 text-stone-600">
                                    {user.profession ?? '—'}
                                </td>
                                <td className="px-4 py-2.5">
                                    {user.activities_count}
                                </td>
                                <td className="px-4 py-2.5">
                                    {user.inbox_items_count}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-stone-500">
                                    {[
                                        user.is_admin && 'admin',
                                        user.own_key && 'own key',
                                        !user.onboarded && 'not onboarded',
                                    ]
                                        .filter(Boolean)
                                        .join(' · ') || '—'}
                                </td>
                                <td className="px-4 py-2.5 text-stone-500">
                                    {user.created_at}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 flex flex-wrap gap-1.5">
                {users.links.map((link, i) =>
                    link.url ? (
                        <Link
                            key={i}
                            href={link.url}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                            className={`rounded-md px-2.5 py-1 text-xs font-semibold ${link.active ? 'bg-brand-tint text-brand-dark' : 'text-stone-500 hover:text-ink'}`}
                        />
                    ) : null,
                )}
            </div>
        </>
    );
}
