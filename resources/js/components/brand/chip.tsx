import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface ChipProps {
    children: ReactNode;
    /** solid = orange tint category chip; dashed = secondary domain chip */
    variant?: 'solid' | 'dashed';
    className?: string;
}

/** Small category/domain chip in the brand's two styles. */
export function Chip({ children, variant = 'solid', className }: ChipProps) {
    return (
        <span
            className={cn(
                'inline-block rounded-full text-[9.5px]',
                variant === 'solid' &&
                    'bg-brand-tint px-[9px] py-[2.5px] font-bold tracking-[0.08em] text-brand-dark uppercase',
                variant === 'dashed' &&
                    'border-[1.5px] border-dashed border-stone-400 px-2 py-[2px] font-semibold text-stone-600',
                className,
            )}
        >
            {children}
        </span>
    );
}
