import { Head, router } from '@inertiajs/react';
import { Loader2, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface ProjectData {
    id: number;
    kind: 'project' | 'objective';
    title: string;
    description: string | null;
    status: 'open' | 'achieved' | 'carried_over';
    due_on: string | null;
    activities_count: number;
    points: number;
}

const STATUS_LABELS: Record<ProjectData['status'], string> = {
    open: 'Open',
    achieved: 'Achieved',
    carried_over: 'Carried over',
};

export default function ProjectsIndex({
    projects,
}: {
    projects: ProjectData[];
}) {
    const [editing, setEditing] = useState<ProjectData | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Projects" />

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="font-display text-[32px] leading-none font-semibold tracking-[-0.01em]">
                        Projects &amp; objectives
                    </h1>
                    <p className="mt-1 text-[12.5px] font-semibold text-stone-500">
                        Your PDP — link activities to these and the AI keeps
                        score.
                    </p>
                </div>
                <Button
                    onClick={() => setCreating(true)}
                    className="rotate-[-1deg] border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                >
                    <Plus className="size-4" /> Add
                </Button>
            </div>

            {projects.length === 0 ? (
                <div className="rounded-[14px] border-2 border-dashed border-stone-400 bg-white px-6 py-14 text-center">
                    <h2 className="font-display text-2xl font-semibold">
                        No projects or objectives yet
                    </h2>
                    <p className="mx-auto mt-1 max-w-md text-sm text-pretty text-stone-500">
                        Add your PDP objectives ("Get better at X") and ongoing
                        projects ("AI lung nodule study") — then link activities
                        to them, and the AI will suggest links automatically.
                    </p>
                    <CaveatNote rotate={1} className="mt-3">
                        the appraiser will ask. be ready.
                    </CaveatNote>
                </div>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    {projects.map((project, i) => (
                        <button
                            key={project.id}
                            type="button"
                            onClick={() => setEditing(project)}
                            style={{
                                rotate: `${[0.5, -0.4, 0.3, -0.6][i % 4]}deg`,
                            }}
                            className="cursor-pointer rounded-[12px] border-2 border-ink bg-white p-4 text-left shadow-[4px_4px_0_rgba(28,25,23,.12)] transition-transform hover:-translate-y-0.5"
                        >
                            <div className="flex items-center gap-2">
                                <span className="rounded-full bg-brand-tint px-2 py-0.5 text-[9.5px] font-bold tracking-[0.08em] text-brand-dark uppercase">
                                    {project.kind}
                                </span>
                                <span
                                    className={`text-[11px] font-semibold ${project.status === 'achieved' ? 'text-cat-teaching' : 'text-stone-500'}`}
                                >
                                    {STATUS_LABELS[project.status]}
                                </span>
                                {project.due_on && (
                                    <span className="ml-auto text-[11px] text-stone-400">
                                        due {project.due_on}
                                    </span>
                                )}
                            </div>
                            <div className="mt-1.5 text-[15px] font-bold tracking-[-0.01em]">
                                {project.title}
                            </div>
                            {project.description && (
                                <p className="mt-1 line-clamp-2 text-[12.5px] text-stone-500">
                                    {project.description}
                                </p>
                            )}
                            <div className="mt-2.5 text-[12px] text-stone-600">
                                <strong className="text-ink">
                                    {project.activities_count}
                                </strong>{' '}
                                linked activities ·{' '}
                                <strong className="text-ink">
                                    {project.points}
                                </strong>{' '}
                                pts
                            </div>
                        </button>
                    ))}
                </div>
            )}

            {(creating || editing) && (
                <ProjectDialog
                    key={editing?.id ?? 'new'}
                    project={editing}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}
        </>
    );
}

function ProjectDialog({
    project,
    onClose,
}: {
    project: ProjectData | null;
    onClose: () => void;
}) {
    const [values, setValues] = useState({
        kind: project?.kind ?? 'objective',
        title: project?.title ?? '',
        description: project?.description ?? '',
        status: project?.status ?? 'open',
        due_on: project?.due_on ?? '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        const payload = { ...values, due_on: values.due_on || null };
        const options = {
            onSuccess: onClose,
            onError: (errs: Record<string, string>) => setErrors(errs),
            onFinish: () => setProcessing(false),
        };

        if (project) {
            router.put(`/projects/${project.id}`, payload, options);
        } else {
            router.post('/projects', payload, options);
        }
    };

    const remove = () => {
        if (
            !project ||
            !confirm('Delete this? Linked activities keep their other data.')
        ) {
            return;
        }

        setProcessing(true);
        router.delete(`/projects/${project.id}`, {
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle className="font-display text-2xl font-semibold">
                        {project ? 'Edit' : 'New project or objective'}
                    </DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1.5">
                            <Label>Kind</Label>
                            <Select
                                value={values.kind}
                                onValueChange={(v) =>
                                    setValues({
                                        ...values,
                                        kind: v as ProjectData['kind'],
                                    })
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="objective">
                                        PDP objective
                                    </SelectItem>
                                    <SelectItem value="project">
                                        Project
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Status</Label>
                            <Select
                                value={values.status}
                                onValueChange={(v) =>
                                    setValues({
                                        ...values,
                                        status: v as ProjectData['status'],
                                    })
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="open">Open</SelectItem>
                                    <SelectItem value="achieved">
                                        Achieved
                                    </SelectItem>
                                    <SelectItem value="carried_over">
                                        Carried over
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="project-title">Title</Label>
                        <Input
                            id="project-title"
                            value={values.title}
                            onChange={(e) =>
                                setValues({ ...values, title: e.target.value })
                            }
                            placeholder="e.g. Improve paediatric imaging reporting"
                        />
                        <InputError message={errors.title} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="project-description">
                            Description (optional)
                        </Label>
                        <textarea
                            id="project-description"
                            value={values.description}
                            rows={3}
                            onChange={(e) =>
                                setValues({
                                    ...values,
                                    description: e.target.value,
                                })
                            }
                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="project-due">
                            Target date (optional)
                        </Label>
                        <Input
                            id="project-due"
                            type="date"
                            value={values.due_on}
                            onChange={(e) =>
                                setValues({ ...values, due_on: e.target.value })
                            }
                        />
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                        >
                            {processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}{' '}
                            Save
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            className="border-2 border-ink"
                        >
                            Cancel
                        </Button>
                        {project && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={remove}
                                className="ml-auto text-red-600 hover:text-red-700"
                            >
                                <Trash2 className="size-4" /> Delete
                            </Button>
                        )}
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
