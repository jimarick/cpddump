import { Head, router } from '@inertiajs/react';
import HeadingSmall from '@/components/heading';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

interface Props {
    weeklyEmailEnabled: boolean;
    weeklyLearningRecapEnabled: boolean;
    monthlyDigestEmailEnabled: boolean;
    pushMorningGemEnabled: boolean;
    pushWeeklyNudgeEnabled: boolean;
    hasPushTokens: boolean;
}

function Toggle({
    checked,
    disabled,
    field,
    label,
    hint,
}: {
    checked: boolean;
    disabled?: boolean;
    field: string;
    label: string;
    hint?: string;
}) {
    return (
        <label
            className={`mt-3 flex items-start gap-2.5 text-sm ${disabled ? 'opacity-50' : ''}`}
        >
            <Checkbox
                checked={checked}
                disabled={disabled}
                onCheckedChange={(v) =>
                    router.patch('/settings/notifications', {
                        [field]: v === true,
                    })
                }
                className="mt-0.5"
            />
            <span>
                <Label>{label}</Label>
                {hint && (
                    <span className="block text-[12.5px] text-stone-500">
                        {hint}
                    </span>
                )}
            </span>
        </label>
    );
}

export default function NotificationsSettings({
    weeklyEmailEnabled,
    weeklyLearningRecapEnabled,
    monthlyDigestEmailEnabled,
    pushMorningGemEnabled,
    pushWeeklyNudgeEnabled,
    hasPushTokens,
}: Props) {
    return (
        <>
            <Head title="Notification settings" />

            <div className="space-y-10">
                <div>
                    <HeadingSmall
                        title="Weekly review email"
                        description="A Monday-morning summary of your week and what's waiting."
                    />
                    <Toggle
                        checked={weeklyEmailEnabled}
                        field="weekly_email_enabled"
                        label="Send me the weekly review"
                    />
                    <div className="ml-6">
                        <Toggle
                            checked={weeklyLearningRecapEnabled}
                            disabled={!weeklyEmailEnabled}
                            field="weekly_learning_recap_enabled"
                            label="Include “what you learned this week”"
                            hint="Your nuggets and actions from the week, for a quick revision pass. Only appears in weeks you recorded some."
                        />
                    </div>
                </div>

                <div>
                    <HeadingSmall
                        title="Monthly learning digest"
                        description="Your month's nuggets and actions in one email, on the 1st. Skipped entirely when there's nothing to show."
                    />
                    <Toggle
                        checked={monthlyDigestEmailEnabled}
                        field="monthly_digest_email_enabled"
                        label="Send me the monthly digest"
                    />
                </div>

                <div>
                    <HeadingSmall
                        title="iPhone notifications"
                        description={
                            hasPushTokens
                                ? 'Delivered through the CPD Dump iOS app.'
                                : 'These need the CPD Dump iOS app signed in on your phone.'
                        }
                    />
                    <Toggle
                        checked={pushMorningGemEnabled}
                        field="push_morning_gem_enabled"
                        label="Morning gem"
                        hint="One nugget from your CPD, daily at 8am. Nothing recorded, nothing sent."
                    />
                    <Toggle
                        checked={pushWeeklyNudgeEnabled}
                        field="push_weekly_nudge_enabled"
                        label="Weekly nudge"
                        hint="A Monday-evening poke when items are waiting for review."
                    />
                </div>
            </div>
        </>
    );
}
