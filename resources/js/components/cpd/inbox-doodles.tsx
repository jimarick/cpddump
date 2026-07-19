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
                    <path d="M3.6 4.2 Q15 2.7 27.4 3.5 Q28.8 3.6 28.4 5.1 L28.2 19.5 Q28.3 21.2 26.6 21 L4.5 21.6 Q2.8 21.8 3 20 L3.6 4.2 Z" />
                    <path d="M3.2 5.4 Q9 9.9 14.7 13.7 Q15.4 14.2 16.2 13.6 L27.6 5.6" />
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
                    <path d="M4.2 3.1 Q3 3.3 3.2 4.6 L2.9 27.2 Q2.9 28.8 4.4 28.6 L21.4 28.2 Q22.9 28.3 22.7 26.8 L23.2 4.4 Q23.3 2.9 21.8 3.1 L4.2 3.1 Z" />
                    <path d="M7.2 9.3 Q12 8.6 16.9 9.1" />
                    <path d="M7 15.2 Q12.1 15.8 17.1 15" />
                    <path d="M7.1 21.1 Q10.1 20.6 13.1 21.2" />
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
                    <path d="M14 3.2 C20.1 2.7 25.4 8 24.9 14.2 C24.4 20.3 19.6 25.3 13.5 24.8 C7.6 24.4 3 19.5 3.3 13.4 C3.6 7.5 8.3 3.6 14.4 3.3" />
                    <path d="M14.2 8.3 Q13.7 11.1 14 13.9" />
                    <path d="M14 14 Q16.5 15.5 19 16.9" />
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
                    <path d="M4.2 8.2 Q3 8.4 3.1 9.7 L2.8 25.3 Q2.8 26.9 4.4 26.7 L25.8 26.3 Q27.3 26.4 27.1 24.9 L27.5 9.3 Q27.6 7.8 26 8 L4.2 8.2 Z" />
                    <path d="M10.3 7.7 L10 4.7 Q10 3.9 10.9 4 L19.3 3.7 Q20.1 3.7 20 4.6 L20.3 7.4" />
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
                    <path d="M5.3 2.5 L16.7 2.1 Q17.3 2.1 17.8 2.6 L22.7 7.7 Q23.1 8.1 23.1 8.7 L22.6 25.2 Q22.7 26.4 21.5 26.3 L5.7 26.7 Q4.4 26.8 4.5 25.5 L5.3 2.5 Z" />
                    <path d="M17.1 2.5 L16.9 7.8 Q16.9 8.5 17.7 8.4 L22.8 8.4" />
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
                    <path d="M13 3.3 C18.5 2.7 23.3 7.4 22.9 12.9 C22.6 18.4 18.2 22.9 12.7 22.8 C7.3 22.7 3.2 18.3 3.1 13 C3 7.8 7.7 3.6 13.4 3.4" />
                    <path
                        d="M8.9 13.2 Q10.6 14.6 11.9 16.4 Q14.6 12.4 18.3 9.8"
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
                    <path d="M10.2 3.4 L18.4 3.1 C22.9 3.1 26.3 6.6 25.9 11 C25.6 15.2 22.3 18.7 17.9 18.8 L9.7 19.1 C5.4 19 2.2 15.5 2.4 11.2 C2.6 7 5.9 3.6 10.2 3.4 Z" />
                    <path d="M30.2 7.3 Q33 5.2 35.9 3.3" stroke="#f4590c" />
                    <path d="M30 12.1 Q33.5 11.7 37 12" stroke="#f4590c" />
                    <path d="M30.1 16.8 Q33.1 18.8 36 20.8" stroke="#f4590c" />
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
                    d="M13 1.4 Q14.3 5.9 15.8 9.2 Q19.7 10.8 23.6 12 Q19.7 13.4 15.7 14.8 Q14.4 18.8 13 22.6 Q11.7 18.7 10.3 14.7 Q6.3 13.3 2.4 12 Q6.4 10.7 10.2 9.3 Q11.6 5.4 13 1.4 Z"
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
                    <path d="M4.2 3.3 Q2.9 3.3 3.1 4.7 L2.8 18.2 Q2.8 19.7 4.3 19.6 L25.8 19.2 Q27.2 19.3 27 17.9 L27.4 4.2 Q27.5 2.8 26 3 L4.2 3.3 Z" />
                    <path d="M6.3 24.2 Q15.1 23.5 23.8 24.1" />
                    <path d="M15.2 19.8 Q14.9 21.9 15.1 24.1" />
                </g>
            </svg>
        ),
    },
];
