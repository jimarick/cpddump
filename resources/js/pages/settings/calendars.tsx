import { Head, router, useForm } from '@inertiajs/react';
import { Loader2, RefreshCw, Trash2, Upload } from 'lucide-react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface FeedData {
    id: number;
    label: string;
    provider_hint: string | null;
    status: 'active' | 'failing' | 'disabled';
    last_sync_error: string | null;
    last_synced_at: string | null;
}

const PROVIDER_HELP: Record<string, string> = {
    google: 'Google Calendar → Settings → your calendar → "Integrate calendar" → copy the Secret address in iCal format.',
    outlook:
        'Outlook → Settings → Calendar → Shared calendars → Publish a calendar → copy the ICS link.',
    nhsmail:
        'NHSmail may block publishing (tenant policy). If you can\'t find "Publish a calendar" in OWA, use the .ics export upload below, or forward meeting invites to your dump address.',
    other: 'Any https:// or webcal:// link that serves an .ics calendar works.',
};

export default function CalendarSettings({ feeds }: { feeds: FeedData[] }) {
    const form = useForm({ label: '', url: '', provider_hint: 'google' });
    const importForm = useForm<{ file: File | null }>({ file: null });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/settings/calendars', { onSuccess: () => form.reset() });
    };

    const submitImport = (e: FormEvent) => {
        e.preventDefault();
        importForm.post('/settings/calendars/import', {
            forceFormData: true,
            onSuccess: () => importForm.reset(),
        });
    };

    return (
        <>
            <Head title="Calendar settings" />

            <div className="space-y-10">
                <div>
                    <Heading
                        title="Connected calendars"
                        description="Paste your calendar's private ICS link. Every week, finished meetings, MDTs and teaching become draft inbox items — bin one with 'never again' and it stays gone."
                    />

                    {feeds.length > 0 && (
                        <div className="mt-4 overflow-hidden rounded-[10px] border-2 border-ink bg-white">
                            {feeds.map((feed, i) => (
                                <div
                                    key={feed.id}
                                    className={`flex items-center gap-3 px-3 py-2.5 ${i ? 'border-t border-ink/7' : ''}`}
                                >
                                    <span
                                        className={`size-2.5 shrink-0 rounded-full ${feed.status === 'active' ? 'bg-cat-teaching' : feed.status === 'failing' ? 'bg-red-500' : 'bg-stone-300'}`}
                                        title={feed.status}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13px] font-semibold">
                                            {feed.label}
                                        </span>
                                        <span className="block truncate text-[11px] text-stone-400">
                                            {feed.status === 'failing'
                                                ? `Sync failing: ${feed.last_sync_error ?? 'unknown error'}`
                                                : feed.last_synced_at
                                                  ? `Last synced ${new Date(feed.last_synced_at).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}`
                                                  : 'First sync pending…'}
                                        </span>
                                    </span>
                                    <button
                                        type="button"
                                        title="Sync now"
                                        onClick={() =>
                                            router.post(
                                                `/settings/calendars/${feed.id}/sync`,
                                            )
                                        }
                                        className="cursor-pointer text-stone-400 hover:text-ink"
                                    >
                                        <RefreshCw className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        title="Disconnect"
                                        onClick={() =>
                                            confirm(
                                                'Disconnect this calendar? Imported items stay.',
                                            ) &&
                                            router.delete(
                                                `/settings/calendars/${feed.id}`,
                                            )
                                        }
                                        className="cursor-pointer text-stone-400 hover:text-red-600"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}

                    <form
                        onSubmit={submit}
                        className="mt-4 grid max-w-md gap-4"
                    >
                        <div className="grid gap-1.5">
                            <Label>Calendar type</Label>
                            <Select
                                value={form.data.provider_hint}
                                onValueChange={(v) =>
                                    form.setData('provider_hint', v)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="google">
                                        Google Calendar
                                    </SelectItem>
                                    <SelectItem value="outlook">
                                        Outlook / Microsoft 365
                                    </SelectItem>
                                    <SelectItem value="nhsmail">
                                        NHSmail
                                    </SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-[12px] leading-relaxed text-pretty text-stone-500">
                                {PROVIDER_HELP[form.data.provider_hint]}
                            </p>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="feed-label">Name it</Label>
                            <Input
                                id="feed-label"
                                value={form.data.label}
                                onChange={(e) =>
                                    form.setData('label', e.target.value)
                                }
                                placeholder="e.g. Work calendar"
                            />
                            <InputError message={form.errors.label} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="feed-url">ICS link</Label>
                            <Input
                                id="feed-url"
                                value={form.data.url}
                                onChange={(e) =>
                                    form.setData('url', e.target.value)
                                }
                                placeholder="https://calendar.google.com/calendar/ical/…/basic.ics"
                                autoComplete="off"
                            />
                            <InputError message={form.errors.url} />
                            <p className="text-[12px] text-stone-500">
                                Treat this link like a password — anyone with it
                                can read your calendar. We store it encrypted.
                            </p>
                        </div>

                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="justify-self-start border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                        >
                            {form.processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            Connect calendar
                        </Button>
                    </form>
                </div>

                <div className="border-t border-dashed border-stone-300 pt-6">
                    <Heading
                        title="One-off import"
                        description="Export an .ics file from any calendar (the NHSmail workaround) and import this appraisal year's past events."
                    />
                    <form
                        onSubmit={submitImport}
                        className="mt-3 flex flex-wrap items-center gap-2"
                    >
                        <Input
                            type="file"
                            accept=".ics,text/calendar"
                            className="max-w-xs"
                            onChange={(e) =>
                                importForm.setData(
                                    'file',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={
                                importForm.processing || !importForm.data.file
                            }
                            className="border-2 border-ink font-semibold"
                        >
                            {importForm.processing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Upload className="size-4" />
                            )}
                            Import
                        </Button>
                    </form>
                    <InputError
                        message={importForm.errors.file}
                        className="mt-1.5"
                    />
                </div>
            </div>
        </>
    );
}
