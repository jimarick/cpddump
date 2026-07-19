import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';

/**
 * The animated watermark for the inbox tray's empty space: a field of
 * faint evidence doodles that bob idly, flee the cursor, and bounce off
 * the walls and each other before drifting home. Purely decorative —
 * pointer-events off, static under prefers-reduced-motion.
 */
export function InboxDoodles() {
    const fieldRef = useRef<HTMLDivElement>(null);
    const itemRefs = useRef<(HTMLDivElement | null)[]>([]);

    useEffect(() => {
        const field = fieldRef.current;

        if (
            !field ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return; // Static fallback: the % positions from render stand.
        }

        const state = DOODLES.map((d) => ({
            x: 0,
            y: d.top,
            vx: 0,
            vy: 0,
            hx: 0,
            hy: d.top,
            radius: d.size / 2,
            phase: Math.random() * Math.PI * 2,
            bobSpeed: 0.9 + Math.random() * 0.5,
        }));

        // In physics mode the field covers the tray's whole empty region:
        // from under the label down to the Regulars strip. The items grid
        // (tagged data-doodle-obstacle) provides a moving ceiling.
        field.style.top = '24px';
        field.style.bottom = '52px';
        field.style.height = 'auto';

        const obstacle =
            field.parentElement?.querySelector('[data-doodle-obstacle]') ??
            null;

        let width = 0;
        let height = 0;
        let ceiling = 0;
        let started = false;

        const measure = () => {
            width = field.offsetWidth;
            height = field.offsetHeight;

            // The lowest item card's bottom edge, in field coordinates.
            const lastItem = obstacle?.lastElementChild;

            if (lastItem) {
                const fieldTop = field.getBoundingClientRect().top;
                ceiling = Math.max(
                    0,
                    lastItem.getBoundingClientRect().bottom - fieldTop + 6,
                );
            }

            const zone = height - ceiling;

            // Not enough room to play in: hide rather than squish.
            field.style.opacity = zone < 80 ? '0' : '1';

            state.forEach((s, i) => {
                s.hx = (DOODLES[i].leftPct / 100) * width;
                s.hy =
                    ceiling +
                    20 +
                    (DOODLES[i].top / 190) * Math.max(zone - 60, 30);

                if (!started) {
                    s.x = s.hx;
                    s.y = s.hy;
                }
            });

            started = true;
        };

        measure();
        const resizeObserver = new ResizeObserver(measure);
        resizeObserver.observe(field);

        if (obstacle) {
            resizeObserver.observe(obstacle);
        }

        // Physics items are absolutely positioned from the origin and
        // driven entirely by transform.
        itemRefs.current.forEach((el) => {
            if (el) {
                el.style.left = '0px';
                el.style.top = '0px';
            }
        });

        let mouse: { x: number; y: number } | null = null;

        const onMove = (e: MouseEvent) => {
            const rect = field.getBoundingClientRect();
            mouse = { x: e.clientX - rect.left, y: e.clientY - rect.top };
        };
        const onLeave = () => {
            mouse = null;
        };

        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseout', onLeave);

        const FLEE_RADIUS = 100;
        const FLEE_FORCE = 1.4;
        const HOME_SPRING = 0.004;
        const DAMPING = 0.93;

        let frame = 0;
        let ticks = 0;

        const tick = (t: number) => {
            // Items come and go (approve, bin, new arrivals) — re-check the
            // ceiling every half-second or so.
            if (++ticks % 30 === 0) {
                measure();
            }

            for (const s of state) {
                // Spring home.
                s.vx += (s.hx - s.x) * HOME_SPRING;
                s.vy += (s.hy - s.y) * HOME_SPRING;

                // Flee the cursor.
                if (mouse) {
                    const dx = s.x - mouse.x;
                    const dy = s.y - mouse.y;
                    const d = Math.hypot(dx, dy);

                    if (d > 0 && d < FLEE_RADIUS) {
                        const push =
                            ((FLEE_RADIUS - d) / FLEE_RADIUS) * FLEE_FORCE;
                        s.vx += (dx / d) * push;
                        s.vy += (dy / d) * push;
                    }
                }
            }

            // Bounce off each other (equal-mass elastic).
            for (let i = 0; i < state.length; i++) {
                for (let j = i + 1; j < state.length; j++) {
                    const a = state[i];
                    const b = state[j];
                    const dx = b.x - a.x;
                    const dy = b.y - a.y;
                    const d = Math.hypot(dx, dy);
                    const min = a.radius + b.radius;

                    if (d > 0 && d < min) {
                        const nx = dx / d;
                        const ny = dy / d;
                        const overlap = (min - d) / 2;

                        a.x -= nx * overlap;
                        a.y -= ny * overlap;
                        b.x += nx * overlap;
                        b.y += ny * overlap;

                        const rel = (a.vx - b.vx) * nx + (a.vy - b.vy) * ny;

                        if (rel > 0) {
                            a.vx -= rel * nx;
                            a.vy -= rel * ny;
                            b.vx += rel * nx;
                            b.vy += rel * ny;
                        }
                    }
                }
            }

            for (let i = 0; i < state.length; i++) {
                const s = state[i];

                s.vx *= DAMPING;
                s.vy *= DAMPING;
                s.x += s.vx;
                s.y += s.vy;

                // Bounce off the tray walls.
                if (s.x < s.radius) {
                    s.x = s.radius;
                    s.vx = Math.abs(s.vx) * 0.8;
                } else if (s.x > width - s.radius) {
                    s.x = width - s.radius;
                    s.vx = -Math.abs(s.vx) * 0.8;
                }

                // The lowest inbox item is the ceiling; the tray floor is
                // the floor.
                if (s.y < ceiling + s.radius) {
                    s.y = ceiling + s.radius;
                    s.vy = Math.abs(s.vy) * 0.8;
                } else if (s.y > height - s.radius) {
                    s.y = height - s.radius;
                    s.vy = -Math.abs(s.vy) * 0.8;
                }

                // Idle bob rides on top of the physics position.
                const bob = Math.sin(t * 0.0012 * s.bobSpeed + s.phase) * 3;

                const el = itemRefs.current[i];

                if (el) {
                    el.style.transform = `translate(${s.x - s.radius}px, ${
                        s.y - s.radius + bob
                    }px) rotate(${DOODLES[i].rotate}deg)`;
                }
            }

            frame = requestAnimationFrame(tick);
        };

        frame = requestAnimationFrame(tick);

        return () => {
            cancelAnimationFrame(frame);
            resizeObserver.disconnect();
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseout', onLeave);
        };
    }, []);

    return (
        <div
            ref={fieldRef}
            aria-hidden
            className="pointer-events-none absolute inset-x-0 bottom-14 h-[190px] select-none"
        >
            {DOODLES.map((d, i) => (
                <div
                    key={i}
                    ref={(el) => {
                        itemRefs.current[i] = el;
                    }}
                    className="absolute"
                    style={{
                        left: `${d.leftPct}%`,
                        top: d.top,
                        transform: `rotate(${d.rotate}deg)`,
                    }}
                >
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

interface Doodle {
    leftPct: number;
    top: number;
    rotate: number;
    size: number;
    svg: ReactNode;
}

const DOODLES: Doodle[] = [
    {
        // Envelope
        leftPct: 9,
        top: 30,
        rotate: -6,
        size: 34,
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
        leftPct: 28,
        top: 55,
        rotate: 5,
        size: 32,
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
        leftPct: 48,
        top: 28,
        rotate: -4,
        size: 28,
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
        leftPct: 67,
        top: 52,
        rotate: 6,
        size: 30,
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
        leftPct: 86,
        top: 30,
        rotate: -5,
        size: 28,
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
        leftPct: 17,
        top: 120,
        rotate: 4,
        size: 26,
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
        leftPct: 38,
        top: 125,
        rotate: -5,
        size: 40,
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
        // Sparkle — keeps its CSS twinkle on the svg itself
        leftPct: 58,
        top: 118,
        rotate: 0,
        size: 26,
        svg: (
            <svg
                width="26"
                height="26"
                viewBox="0 0 26 26"
                className="cpd-doodle"
                style={{ animation: 'dv-twinkle 3.2s ease-in-out infinite' }}
            >
                <path
                    d="M13 1 L15.7 9.3 L24 12 L15.7 14.7 L13 23 L10.3 14.7 L2 12 L10.3 9.3 Z"
                    fill="#f4590c"
                />
            </svg>
        ),
    },
    {
        // Monitor / screen
        leftPct: 76,
        top: 122,
        rotate: -4,
        size: 30,
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
