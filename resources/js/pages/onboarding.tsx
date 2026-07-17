import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import { StampLogo } from '@/components/brand/stamp-logo';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForceLight } from '@/hooks/use-force-light';

const gridBg = {
    backgroundImage:
        'linear-gradient(rgba(28,25,23,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(28,25,23,.045) 1px,transparent 1px)',
    backgroundSize: '52px 52px',
};

interface Props {
    professions: { id: number; slug: string; name: string }[];
    defaults: { starts_on: string; ends_on: string };
}

export default function Onboarding({ professions, defaults }: Props) {
    useForceLight();

    const form = useForm({
        profession_id: professions[0]?.id ?? 0,
        starts_on: defaults.starts_on,
        ends_on: defaults.ends_on,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/onboarding');
    };

    return (
        <>
            <Head title="Set up" />
            <div
                className="flex min-h-svh flex-col items-center justify-center bg-paper p-6 font-sans text-ink"
                style={gridBg}
            >
                <div className="w-full max-w-md">
                    <div className="mb-7 flex -rotate-1 justify-center">
                        <StampLogo size="lg" />
                    </div>

                    <form
                        onSubmit={submit}
                        className="-rotate-[0.6deg] rounded-[14px] border-2 border-ink bg-white px-6 py-7 shadow-[6px_6px_0_rgba(28,25,23,.12)] md:px-7"
                    >
                        <h1 className="text-center font-display text-[28px] leading-[1.1] font-semibold tracking-[-0.01em]">
                            Two questions. Then dump away.
                        </h1>

                        <div className="mt-6 grid gap-2">
                            <Label>What are you?</Label>
                            <div className="grid gap-2">
                                {professions.map((p) => (
                                    <button
                                        key={p.id}
                                        type="button"
                                        onClick={() =>
                                            form.setData('profession_id', p.id)
                                        }
                                        className={`cursor-pointer rounded-[10px] border-2 px-4 py-3 text-left text-sm font-semibold transition-colors ${
                                            form.data.profession_id === p.id
                                                ? 'border-ink bg-brand-tint text-brand-dark shadow-[3px_3px_0_rgba(28,25,23,.12)]'
                                                : 'border-dashed border-stone-400 text-stone-600 hover:border-ink'
                                        }`}
                                    >
                                        {p.name}
                                        {p.slug === 'uk-doctor' && (
                                            <span className="mt-0.5 block text-xs font-normal text-stone-500">
                                                Categorised against GMC Good
                                                Medical Practice —
                                                appraisal-ready.
                                            </span>
                                        )}
                                    </button>
                                ))}
                                <div className="rounded-[10px] border-2 border-dashed border-stone-300 px-4 py-3 text-left text-sm text-stone-400">
                                    Other professions coming soon
                                </div>
                            </div>
                            <InputError message={form.errors.profession_id} />
                        </div>

                        <div className="mt-5 grid gap-2">
                            <Label>When's your appraisal year?</Label>
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <Input
                                        type="date"
                                        value={form.data.starts_on}
                                        onChange={(e) =>
                                            form.setData(
                                                'starts_on',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.starts_on}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Input
                                        type="date"
                                        value={form.data.ends_on}
                                        onChange={(e) =>
                                            form.setData(
                                                'ends_on',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.ends_on}
                                        className="mt-1"
                                    />
                                </div>
                            </div>
                            <p className="text-xs text-stone-500">
                                Defaults to the standard April-to-March year.
                                You can change or reset it any time.
                            </p>
                        </div>

                        <Button
                            type="submit"
                            className="mt-6 w-full"
                            disabled={form.processing}
                        >
                            Start dumping
                        </Button>
                    </form>

                    <CaveatNote rotate={-1.5} className="mt-4 text-center">
                        that's it. no forms about forms.
                    </CaveatNote>
                </div>
            </div>
        </>
    );
}
