import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface CaveatNoteProps {
    children: ReactNode;
    /** Degrees of rotation, brand range is roughly -2..4 */
    rotate?: number;
    color?: 'gray' | 'brand';
    className?: string;
}

/** Hand-scrawled Caveat annotation, always slightly rotated. */
export function CaveatNote({
    children,
    rotate = -1.5,
    color = 'gray',
    className,
}: CaveatNoteProps) {
    return (
        <div
            style={{ rotate: `${rotate}deg` }}
            className={cn(
                'font-hand text-[17px]',
                color === 'gray' ? 'text-stone-500' : 'text-brand',
                className,
            )}
        >
            {children}
        </div>
    );
}
