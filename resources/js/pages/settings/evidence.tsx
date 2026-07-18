import { Head, router } from '@inertiajs/react';
import { Check, Copy, Trash2 } from 'lucide-react';
import { useState } from 'react';
import HeadingSmall from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

interface IgnoreRuleData {
    id: number;
    source: string | null;
    field: string;
    operator: string;
    value: string;
    is_active: boolean;
    hit_count: number;
}

interface Props {
    dumpAddress: string | null;
    weeklyEmailEnabled: boolean;
    ignoreRules: IgnoreRuleData[];
}

export default function EvidenceSettings({
    dumpAddress,
    weeklyEmailEnabled,
    ignoreRules,
}: Props) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (!dumpAddress) {
            return;
        }

        await navigator.clipboard.writeText(dumpAddress);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <>
            <Head title="Evidence settings" />

            <div className="space-y-10">
                <div>
                    <HeadingSmall
                        title="Your dump address"
                        description="Forward anything CPD-shaped here and it lands in your inbox, analysed."
                    />
                    {dumpAddress ? (
                        <div className="mt-3 flex items-center gap-2">
                            <code className="rounded-md bg-brand-tint px-3 py-2 font-mono text-[13px] text-brand-dark">
                                {dumpAddress}
                            </code>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={copy}
                                className="border-2 border-ink"
                            >
                                {copied ? (
                                    <Check className="size-3.5" />
                                ) : (
                                    <Copy className="size-3.5" />
                                )}
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    ) : (
                        <p className="mt-3 text-sm text-stone-500">
                            Finish onboarding to get your address.
                        </p>
                    )}
                    <p className="mt-2 text-[12.5px] text-pretty text-stone-500">
                        Tip: add it to your contacts as "CPD Dump" so forwarding
                        is two taps. Never forward anything containing
                        patient-identifiable information.
                    </p>
                    {dumpAddress && (
                        <div className="mt-4 border-t border-dashed border-stone-300 pt-3">
                            <button
                                type="button"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            'Generate a new address? The old one stops working immediately — anywhere you saved it (contacts, forwarding rules) will need updating.',
                                        )
                                    ) {
                                        router.post(
                                            '/settings/evidence/regenerate-address',
                                        );
                                    }
                                }}
                                className="cursor-pointer text-[12.5px] font-semibold text-stone-500 underline decoration-dashed underline-offset-4 hover:text-ink"
                            >
                                Address leaked or getting junk? Generate a new
                                one
                            </button>
                        </div>
                    )}
                </div>

                <div>
                    <HeadingSmall
                        title="Weekly review email"
                        description="A Monday-morning summary of your week and what's waiting."
                    />
                    <label className="mt-3 flex items-center gap-2.5 text-sm">
                        <Checkbox
                            checked={weeklyEmailEnabled}
                            onCheckedChange={(v) =>
                                router.patch('/settings/evidence', {
                                    weekly_email_enabled: v === true,
                                })
                            }
                        />
                        <Label>Send me the weekly review</Label>
                    </label>
                </div>

                <div>
                    <HeadingSmall
                        title="Ignore rules"
                        description="Items matching these never reach your inbox. Created when you bin something with “never again”."
                    />
                    {ignoreRules.length === 0 ? (
                        <p className="mt-3 text-sm text-stone-500">
                            None yet. When you bin a calendar or email item
                            you'll get the option.
                        </p>
                    ) : (
                        <div className="mt-3 overflow-hidden rounded-[10px] border-2 border-ink bg-white">
                            {ignoreRules.map((rule, i) => (
                                <div
                                    key={rule.id}
                                    className={`flex items-center gap-3 px-3 py-2.5 ${i ? 'border-t border-ink/7' : ''}`}
                                >
                                    <Checkbox
                                        checked={rule.is_active}
                                        onCheckedChange={() =>
                                            router.patch(
                                                `/settings/evidence/rules/${rule.id}`,
                                            )
                                        }
                                        title={
                                            rule.is_active
                                                ? 'Active — click to pause'
                                                : 'Paused — click to enable'
                                        }
                                    />
                                    <span
                                        className={`min-w-0 flex-1 truncate text-[13px] ${rule.is_active ? '' : 'text-stone-400 line-through'}`}
                                    >
                                        {rule.source ? `${rule.source}: ` : ''}
                                        {rule.field} {rule.operator} “
                                        {rule.value}”
                                    </span>
                                    <span className="text-[11px] whitespace-nowrap text-stone-400">
                                        skipped {rule.hit_count}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.delete(
                                                `/settings/evidence/rules/${rule.id}`,
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
                </div>
            </div>
        </>
    );
}
