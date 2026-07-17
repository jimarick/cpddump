interface SparkleProps {
    size?: number;
    className?: string;
}

/** The 4-point diamond sparkle that marks every AI feature. */
export function Sparkle({ size = 16, className }: SparkleProps) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 20 20"
            className={className}
            aria-hidden="true"
        >
            <path
                d="M10 1 L12.2 7.8 L19 10 L12.2 12.2 L10 19 L7.8 12.2 L1 10 L7.8 7.8 Z"
                fill="currentColor"
            />
        </svg>
    );
}
