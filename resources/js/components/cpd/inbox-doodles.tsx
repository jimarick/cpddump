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

                // Spread the two design rows across the WHOLE empty zone:
                // 155 is the deepest design top, so fractions run ~0.18–0.81
                // and the field fills top to bottom.
                s.hy =
                    ceiling +
                    18 +
                    (DOODLES[i].top / 155) * Math.max(zone - 36, 30);

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
    opacity: 0.16,
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
                    <path d="M3.4 5.1 Q4 3.4 6 3.8 L26 2.9 Q29.2 2.7 28.4 5.7 L29.1 18.6 Q29.7 21.6 26.5 21.1 L5.2 22.2 Q2.1 22.7 3 19.6 L3.4 5.1 Z" />
                    <path d="M2.7 5.7 Q8.8 10.6 14.3 14.2 Q15.4 15 16.6 13.9 Q22.2 9.2 28.6 4.9" />
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
                    <path d="M4.9 3.5 Q2.7 2.9 3.3 5.3 L2.4 26.6 Q2 29.5 4.8 29 L20.9 28.1 Q23.6 28.5 23 25.8 L23.8 5 Q24.3 2.2 21.5 2.9 L4.9 3.5 Z" />
                    <path d="M6.8 9.7 Q12.1 8 17.4 9.2" />
                    <path d="M6.9 15.4 Q12.2 16.8 17.5 14.9" />
                    <path d="M7 21.2 Q10.3 19.9 13.6 21.6" />
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
                    <path d="M15.3 3.5 C21.7 3.8 25.9 9.1 24.5 15.1 C23.2 20.9 17.7 25.7 11.8 24.3 C6.3 23.1 2.4 17.8 3.7 12 C4.8 6.9 9.2 3.2 14.4 3.6" />
                    <path d="M14.5 7.9 Q13.4 11.2 14.1 14.3" />
                    <path d="M13.8 13.7 Q16.9 15.1 19.6 17.5" />
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
                    <path d="M4.7 8.7 Q2.4 8.2 3.2 10.5 L2.5 24.6 Q2.1 27.5 5 27 L25.3 26.1 Q27.9 26.6 27.3 24 L28.1 9.9 Q28.7 7.3 26 7.9 L4.7 8.7 Z" />
                    <path d="M10.6 8.9 L9.7 4.8 Q9.5 3.4 11.1 3.7 L19 3.2 Q20.7 3 20.4 4.6 L20.8 8.4" />
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
                    <path d="M5.8 3 L16.3 2.1 Q17.5 2 18.2 2.9 L22.4 7.4 Q23.5 8.2 23.3 9.4 L22.3 24.7 Q22.6 27 20.5 26.6 L6.1 27.3 Q3.8 27.7 4.2 25.2 L5.8 3 Z" />
                    <path d="M16.9 2.2 L16.4 8 Q16.3 9.2 17.7 9 L23.5 8.5" />
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
                    <path d="M13.8 3.6 C19.2 3.1 23.8 8 22.6 13.6 C21.6 18.7 17.2 23.6 11.7 22.3 C6.7 21.2 2.7 16.9 3.6 11.8 C4.4 7 8.7 3.8 13.2 3.8" />
                    <path
                        d="M8.2 13.6 Q10.5 14.8 11.7 17.2 Q14.2 12 19.1 9"
                        stroke="#f4590c"
                    />
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
                    <path d="M10.5 3.8 L17.9 3.1 C23.3 2.7 27 7 25.8 11.7 C24.8 15.9 21.3 19.2 17.1 19.1 L9.1 19.5 C4.5 19.4 1.5 15.1 2.7 10.5 C3.6 6.5 6.6 4 10.5 3.8 Z" />
                    <path d="M30.5 7.7 Q33.3 4.7 36.4 2.9" stroke="#f4590c" />
                    <path
                        d="M29.7 12.3 Q33.7 10.9 37.4 12.2"
                        stroke="#f4590c"
                    />
                    <path
                        d="M30.3 16.4 Q33.5 19.5 36.3 21.4"
                        stroke="#f4590c"
                    />
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
                    d="M13.2 1.1 Q13.7 6.4 15.7 9.5 Q19.2 11.2 24 12.1 Q19.1 13 15.5 14.9 Q14.2 18.4 12.8 23 Q12 18.2 10.2 14.7 Q6.7 13.1 2 11.9 Q6.9 10.9 10.4 9.2 Q12.2 5.7 13.2 1.1 Z"
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
                    <path d="M4.8 3.8 Q2.4 3.2 3.2 5.6 L2.5 17.2 Q2.1 20.3 5.2 19.8 L25 18.9 Q27.7 19.5 27.2 16.9 L28 5 Q28.5 2.4 25.7 3.1 L4.2 3.3 Z" />
                    <path d="M6 24.7 Q15.2 22.9 24.2 24.3" />
                    <path d="M15.5 19.4 Q14.5 22 15.1 24.6" />
                </g>
            </svg>
        ),
    },
];
