import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type BrandButtonProps = {
    variant?: 'primary' | 'secondary';
    /** Degrees of askew rotation; the brand default is -1 (primary) / 0.8 (secondary). */
    rotate?: number;
} & ComponentProps<'button'>;

/**
 * Paper-and-ink brand button: hard offset shadow (never blurred), 2px ink
 * border, slightly askew. Hover lifts (shadow grows), active presses in.
 */
export function BrandButton({
    variant = 'primary',
    rotate,
    className,
    style,
    ...props
}: BrandButtonProps) {
    const deg = rotate ?? (variant === 'primary' ? -1 : 0.8);

    return (
        <button
            {...props}
            style={{ rotate: `${deg}deg`, ...style }}
            className={cn(
                'inline-block cursor-pointer rounded-[10px] border-2 border-ink px-[26px] py-[13px] text-[15.5px] transition-[translate,box-shadow] duration-100',
                variant === 'primary' &&
                    'bg-brand font-bold text-white shadow-[4px_4px_0_#1c1917] hover:-translate-x-px hover:-translate-y-px hover:shadow-[5px_5px_0_#1c1917] active:translate-x-[2px] active:translate-y-[2px] active:shadow-[2px_2px_0_#1c1917]',
                variant === 'secondary' &&
                    'bg-white px-[22px] font-semibold text-ink hover:-translate-x-px hover:-translate-y-px',
                className,
            )}
        />
    );
}
