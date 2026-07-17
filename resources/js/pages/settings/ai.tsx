import { Head, useForm, router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { Sparkle } from '@/components/brand/sparkle';
import HeadingSmall from '@/components/heading';
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

interface Props {
    provider: string | null;
    hasKey: boolean;
    keyHint: string | null;
}

export default function AiSettings({ provider, hasKey, keyHint }: Props) {
    const form = useForm({ provider: provider ?? 'anthropic', key: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch('/settings/ai', {
            onSuccess: () => form.reset('key'),
        });
    };

    return (
        <>
            <Head title="AI settings" />

            <div className="space-y-10">
                <div>
                    <HeadingSmall
                        title="Bring your own AI key"
                        description="By default CPD Dump uses its built-in AI with a fair-use daily allowance. Add your own OpenAI or Anthropic key for unlimited use — it's stored encrypted and only used for your account."
                    />

                    {hasKey && (
                        <div className="mt-3 flex items-center gap-2 rounded-[10px] border-[1.5px] border-dashed border-brand/50 bg-brand-pale px-3 py-2.5 text-[13px]">
                            <Sparkle size={13} className="text-brand" />
                            Using your own{' '}
                            {provider === 'openai' ? 'OpenAI' : 'Anthropic'} key
                            ({keyHint}) for all AI features.
                            <Button
                                variant="ghost"
                                size="sm"
                                className="ml-auto text-red-600 hover:text-red-700"
                                onClick={() => router.delete('/settings/ai')}
                            >
                                Remove
                            </Button>
                        </div>
                    )}

                    <form
                        onSubmit={submit}
                        className="mt-4 grid max-w-md gap-4"
                    >
                        <div className="grid gap-1.5">
                            <Label>Provider</Label>
                            <Select
                                value={form.data.provider}
                                onValueChange={(v) =>
                                    form.setData('provider', v)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="anthropic">
                                        Anthropic (Claude)
                                    </SelectItem>
                                    <SelectItem value="openai">
                                        OpenAI
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="ai-key">API key</Label>
                            <Input
                                id="ai-key"
                                type="password"
                                value={form.data.key}
                                onChange={(e) =>
                                    form.setData('key', e.target.value)
                                }
                                placeholder={
                                    form.data.provider === 'openai'
                                        ? 'sk-…'
                                        : 'sk-ant-…'
                                }
                                autoComplete="off"
                            />
                            <InputError message={form.errors.key} />
                        </div>

                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                form.data.key.trim().length < 20
                            }
                            className="justify-self-start border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                        >
                            {form.processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {hasKey ? 'Replace key' : 'Use my key'}
                        </Button>
                    </form>
                </div>
            </div>
        </>
    );
}
