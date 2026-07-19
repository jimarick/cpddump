import { cn } from '@/lib/utils';

interface WordmarkProps {
    /** sm = footer (14px), md = nav (20px), lg = hero/auth (28px) */
    size?: 'sm' | 'md' | 'lg';
    /** Render just "d." — for tight spaces (mock headers, ≤32px contexts) */
    compact?: boolean;
    className?: string;
}

const SIZES = { sm: 'text-[15px]', md: 'text-[24px]', lg: 'text-[32px]' };

/**
 * The brand wordmark: "cpd dump." in Bricolage Grotesque 800 with the
 * orange full stop. No icon, no rotation — the type is the logo. On dark
 * surfaces pass a text color via className; the stop stays orange.
 */
export function Wordmark({
    size = 'md',
    compact = false,
    className,
}: WordmarkProps) {
    return (
        <span
            className={cn(
                'font-display font-extrabold tracking-[-0.03em] whitespace-nowrap text-ink',
                SIZES[size],
                className,
            )}
        >
            {compact ? 'd' : 'cpd dump'}
            <span className="text-brand">.</span>
        </span>
    );
}
