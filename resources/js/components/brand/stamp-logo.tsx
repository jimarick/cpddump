import { cn } from '@/lib/utils';

interface StampLogoProps {
    /** sm = single ring (≤24px contexts), md = double ring, lg = double ring + subline */
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}

/**
 * The CPD Dump "double-ring stamp" logo. Always slightly rotated; single
 * border only at small sizes per the brand spec.
 */
export function StampLogo({ size = 'md', className }: StampLogoProps) {
    if (size === 'sm') {
        return (
            <span
                className={cn(
                    'inline-block -rotate-4 rounded-[4px] border-[1.5px] border-brand px-[7px] py-[2px] text-[9px] font-bold tracking-[0.05em] text-brand uppercase',
                    className,
                )}
            >
                CPD Dump
            </span>
        );
    }

    return (
        <span
            className={cn(
                'inline-block -rotate-4 rounded-[9px] border-[2.5px] border-brand p-[3px] opacity-[0.93]',
                className,
            )}
        >
            <span className="flex flex-col items-center rounded-[6px] border-[1.5px] border-brand px-[11px] py-[3px]">
                <span className="text-sm font-bold tracking-[0.05em] text-brand uppercase">
                    CPD Dump
                </span>
                {size === 'lg' && (
                    <span className="flex items-center gap-[5px] pb-[2px] text-[8.5px] font-semibold tracking-[0.24em] text-brand uppercase">
                        <span className="size-[4px] rotate-45 bg-brand" />
                        Sorted with AI
                        <span className="size-[4px] rotate-45 bg-brand" />
                    </span>
                )}
            </span>
        </span>
    );
}
