import { useDraggable, useDroppable } from '@dnd-kit/core';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { CaveatNote } from '@/components/brand/caveat-note';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** One paper card on the pending pile. */
export interface PileCard {
    id: number;
    title: string;
    meta: string;
    /** Activity type colour dot; omitted for inbox items. */
    accent?: string;
}

// Gentle: these rows are full-width, so even one degree of rotation
// swings the far edge dozens of pixels.
const PILE_TILTS = [-0.3, 0.5, -0.6, 0.4, -0.5, 0.55];

export const STACK_DROP_ID = 'pending-stack';

/**
 * The fanned pile of stacked evidence: deliberately messier than the list
 * it came from (amplified tilts, overlap, a count sticker), with per-card
 * removal and the merge bar attached underneath. While it exists it is the
 * only drop target — keep dropping to keep stacking.
 */
export function StackedPile({
    cards,
    onRemove,
    onMerge,
    onClear,
    mergeDisabledReason,
}: {
    cards: PileCard[];
    onRemove: (id: number) => void;
    onMerge: () => void;
    onClear: () => void;
    mergeDisabledReason?: string;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: STACK_DROP_ID });

    return (
        <div className="py-2">
            <div
                ref={setNodeRef}
                className={cn(
                    'relative transition-transform',
                    isOver && 'scale-[1.02]',
                )}
            >
                <span className="absolute -top-2.5 -right-2 z-20 flex size-7 rotate-[6deg] items-center justify-center rounded-full border-2 border-paper bg-ink font-display text-[13px] font-extrabold text-paper shadow-[2px_2px_0_rgba(28,25,23,.3)]">
                    {cards.length}
                </span>
                {cards.map((card, i) => (
                    <div
                        key={card.id}
                        style={{
                            rotate: `${PILE_TILTS[i % PILE_TILTS.length]}deg`,
                            marginTop: i === 0 ? 0 : -48,
                            zIndex: cards.length - i,
                            translate: `${i % 2 === 0 ? 0 : 2}px`,
                        }}
                        className={cn(
                            'group relative flex w-full min-w-0 items-center gap-3 rounded-[12px] border-2 border-ink bg-white px-4 py-3 shadow-[3px_3px_0_rgba(28,25,23,.12)]',
                            isOver && 'border-brand',
                        )}
                    >
                        {card.accent && (
                            <span
                                className="size-3 shrink-0 rounded-full border-[1.5px] border-ink"
                                style={{ backgroundColor: card.accent }}
                            />
                        )}
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-[13.5px] font-semibold">
                                {card.title}
                            </span>
                            <span className="block truncate text-[10.5px] font-semibold tracking-[0.06em] text-stone-500 uppercase">
                                {card.meta}
                            </span>
                        </span>
                        <button
                            type="button"
                            title="Take this one off the pile"
                            aria-label={`Remove ${card.title} from the stack`}
                            onClick={() => onRemove(card.id)}
                            className="absolute -top-2 -left-2 z-10 hidden size-5 cursor-pointer items-center justify-center rounded-full border-[1.5px] border-ink bg-white shadow-[1.5px_1.5px_0_#1c1917] group-hover:flex"
                        >
                            <X className="size-3" />
                        </button>
                    </div>
                ))}
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-3">
                <Button
                    onClick={onMerge}
                    disabled={mergeDisabledReason !== undefined}
                    title={mergeDisabledReason}
                    className="rotate-[-0.6deg] border-2 border-ink font-bold shadow-[3px_3px_0_#1c1917]"
                >
                    Merge {cards.length} into one
                </Button>
                <Button
                    variant="outline"
                    onClick={onClear}
                    className="border-2 border-ink font-semibold"
                >
                    Unstack
                </Button>
                <CaveatNote rotate={-2} className="hidden sm:block">
                    drop more on to keep stacking →
                </CaveatNote>
            </div>
            {mergeDisabledReason && (
                <p className="mt-1.5 text-[12.5px] font-semibold text-red-600">
                    {mergeDisabledReason}
                </p>
            )}
        </div>
    );
}

/**
 * Makes a list row both a drag source and (until a stack exists) a drop
 * target. Buttons inside keep working — the pointer sensor only kicks in
 * after 8px of travel.
 */
export function MergeDraggable({
    id,
    dragDisabled,
    dropDisabled,
    children,
}: {
    id: number;
    dragDisabled: boolean;
    dropDisabled: boolean;
    children: ReactNode;
}) {
    const {
        setNodeRef: setDragRef,
        listeners,
        attributes,
        isDragging,
    } = useDraggable({ id, disabled: dragDisabled });
    const { setNodeRef: setDropRef, isOver } = useDroppable({
        id,
        disabled: dropDisabled,
    });

    return (
        <div
            ref={(node) => {
                setDragRef(node);
                setDropRef(node);
            }}
            {...listeners}
            {...attributes}
            className={cn(
                'relative min-w-0 touch-manipulation rounded-[12px] outline-none',
                isDragging && 'opacity-35',
                isOver &&
                    !isDragging &&
                    'z-10 scale-[1.02] ring-[3px] ring-brand/50',
            )}
        >
            {isOver && !isDragging && (
                // Full-row overlay: never clipped by the scroll container,
                // never hidden behind a neighbouring row.
                <span className="pointer-events-none absolute inset-0 z-30 flex items-center justify-end rounded-[12px] bg-brand/10 pr-4">
                    <span className="rounded-[7px] border-[1.5px] border-ink bg-brand px-2 py-0.5 text-[9.5px] font-extrabold tracking-[0.05em] text-white uppercase shadow-[2px_2px_0_#1c1917]">
                        Drop to stack
                    </span>
                </span>
            )}
            {children}
        </div>
    );
}
