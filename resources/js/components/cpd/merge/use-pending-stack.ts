import { useState } from 'react';

/**
 * The client-only pending merge stack: ordered ids, nothing persisted.
 * Membership is derived against live page props each render, so items
 * that vanish (approved elsewhere, binned, polled away) simply drop out;
 * the pile only renders while two or more members survive.
 */
export function usePendingStack() {
    const [stack, setStack] = useState<number[]>([]);

    return {
        stack,
        /** First drop: base row + dragged row become a stack of two. */
        start: (baseId: number, droppedId: number) =>
            setStack(baseId === droppedId ? [] : [baseId, droppedId]),
        add: (id: number) =>
            setStack((s) => (s.includes(id) ? s : [...s, id])),
        remove: (id: number) =>
            setStack((s) => {
                const next = s.filter((v) => v !== id);

                return next.length >= 2 ? next : [];
            }),
        clear: () => setStack([]),
    };
}
