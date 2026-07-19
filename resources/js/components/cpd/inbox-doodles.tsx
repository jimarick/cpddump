import type { CSSProperties, ReactNode } from 'react';

/**
 * The animated watermark for the inbox tray's empty space: a field of
 * faint evidence doodles (emails, certificates, voice notes…) gently
 * bobbing. Positions are percentages so the field stretches with the
 * tray. Purely decorative — pointer-events off, honours
 * prefers-reduced-motion via the .cpd-doodle CSS class.
 */
export function InboxDoodles() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-x-0 bottom-14 h-[190px] select-none"
        >
            {DOODLES.map((d, i) => (
                <div key={i} className="cpd-doodle absolute" style={d.style}>
                    {d.svg}
                </div>
            ))}
        </div>
    );
}

const stroke = {
    opacity: 0.12,
    stroke: '#1c1917',
    strokeWidth: 2.5,
    fill: 'none',
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
} as const;

const DOODLES: { style: CSSProperties; svg: ReactNode }[] = [
    {
        // Envelope
        style: {
            left: '9%',
            top: 30,
            ['--r' as string]: '-6deg',
            animation: 'dv-bob 4.2s ease-in-out infinite',
        },
        svg: (
            <svg width="34" height="28" viewBox="0 0 34 28">
                <g {...stroke}>
                    <rect x="2" y="3" width="26" height="18" rx="3" />
                    <path d="M2 5 L15 14 L28 5" />
                </g>
            </svg>
        ),
    },
    {
        // Document
        style: {
            left: '28%',
            top: 55,
            ['--r' as string]: '5deg',
            animation: 'dv-bob 5.1s ease-in-out .6s infinite',
        },
        svg: (
            <svg width="26" height="32" viewBox="0 0 26 32">
                <g {...stroke}>
                    <rect x="2" y="2" width="20" height="26" rx="3" />
                    <line x1="7" y1="9" x2="17" y2="9" />
                    <line x1="7" y1="15" x2="17" y2="15" />
                    <line x1="7" y1="21" x2="13" y2="21" />
                </g>
            </svg>
        ),
    },
    {
        // Clock
        style: {
            left: '48%',
            top: 28,
            ['--r' as string]: '-4deg',
            animation: 'dv-bob 4.6s ease-in-out 1.2s infinite',
        },
        svg: (
            <svg width="28" height="28" viewBox="0 0 28 28">
                <g {...stroke}>
                    <circle cx="14" cy="14" r="11" />
                    <line x1="14" y1="8" x2="14" y2="14" />
                    <line x1="14" y1="14" x2="19" y2="17" />
                </g>
            </svg>
        ),
    },
    {
        // Briefcase
        style: {
            left: '67%',
            top: 52,
            ['--r' as string]: '6deg',
            animation: 'dv-bob 5.4s ease-in-out .3s infinite',
        },
        svg: (
            <svg width="30" height="30" viewBox="0 0 30 30">
                <g {...stroke}>
                    <rect x="2" y="7" width="26" height="20" rx="3" />
                    <path d="M10 7 v-3 h10 v3" />
                </g>
            </svg>
        ),
    },
    {
        // File / certificate
        style: {
            left: '86%',
            top: 30,
            ['--r' as string]: '-5deg',
            animation: 'dv-bob 4.4s ease-in-out .9s infinite',
        },
        svg: (
            <svg width="26" height="28" viewBox="0 0 26 28">
                <g {...stroke}>
                    <path d="M5 2 h12 l6 6 v16 h-18 Z" />
                    <path d="M17 2 v6 h6" />
                </g>
            </svg>
        ),
    },
    {
        // Check circle (orange tick)
        style: {
            left: '17%',
            top: 120,
            ['--r' as string]: '4deg',
            animation: 'dv-bob 5s ease-in-out 1.5s infinite',
        },
        svg: (
            <svg width="26" height="26" viewBox="0 0 26 26">
                <g {...stroke}>
                    <circle cx="13" cy="13" r="10" />
                    <path d="M9 13 l3 3 l6 -6" stroke="#f4590c" />
                </g>
            </svg>
        ),
    },
    {
        // Voice-note pill (orange waveform)
        style: {
            left: '38%',
            top: 125,
            ['--r' as string]: '-5deg',
            animation: 'dv-sway 3.8s ease-in-out infinite',
        },
        svg: (
            <svg width="40" height="24" viewBox="0 0 40 24">
                <g {...stroke}>
                    <rect x="2" y="3" width="24" height="16" rx="8" />
                    <line x1="30" y1="7" x2="36" y2="3" stroke="#f4590c" />
                    <line x1="30" y1="12" x2="37" y2="12" stroke="#f4590c" />
                    <line x1="30" y1="17" x2="36" y2="21" stroke="#f4590c" />
                </g>
            </svg>
        ),
    },
    {
        // Sparkle (twinkles)
        style: {
            left: '58%',
            top: 118,
            animation: 'dv-twinkle 3.2s ease-in-out infinite',
        },
        svg: (
            <svg width="26" height="26" viewBox="0 0 26 26">
                <path
                    d="M13 1 L15.7 9.3 L24 12 L15.7 14.7 L13 23 L10.3 14.7 L2 12 L10.3 9.3 Z"
                    fill="#f4590c"
                />
            </svg>
        ),
    },
    {
        // Monitor / screen
        style: {
            left: '76%',
            top: 122,
            ['--r' as string]: '-4deg',
            animation: 'dv-bob 4.8s ease-in-out .4s infinite',
        },
        svg: (
            <svg width="30" height="28" viewBox="0 0 30 28">
                <g {...stroke}>
                    <rect x="2" y="2" width="26" height="18" rx="3" />
                    <line x1="6" y1="24" x2="24" y2="24" />
                    <line x1="15" y1="20" x2="15" y2="24" />
                </g>
            </svg>
        ),
    },
];
