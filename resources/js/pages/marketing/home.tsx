import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BrandButton } from '@/components/brand/brand-button';
import { CaveatNote } from '@/components/brand/caveat-note';
import { Chip } from '@/components/brand/chip';
import { Sparkle } from '@/components/brand/sparkle';
import { StampLogo } from '@/components/brand/stamp-logo';
import { useForceLight } from '@/hooks/use-force-light';
import { login, register } from '@/routes';

const gridBg = {
    backgroundImage:
        'linear-gradient(rgba(28,25,23,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(28,25,23,.045) 1px,transparent 1px)',
    backgroundSize: '52px 52px',
};

export default function Home() {
    useForceLight();

    return (
        <>
            <Head title="CPD Dump — Dump it. We'll sort it (with AI).">
                <meta
                    name="description"
                    content="Forward emails, ramble voice notes, snap certificates. AI turns them into categorised, appraisal-ready CPD evidence for UK doctors and allied health professionals."
                />
            </Head>
            <div className="min-h-screen bg-paper font-sans text-ink">
                <Nav />
                <Hero />
                <Sources />
                <InboxMockup />
                <Timeline />
                <AiSection />
                <Testimonials />
                <PrivacyBanner />
                <FinalCta />
                <Footer />
            </div>
        </>
    );
}

function Nav() {
    return (
        <div className="flex items-center justify-between border-b border-dashed border-ink/18 px-5 py-[18px] md:px-12">
            <StampLogo size="md" />
            <div className="hidden items-center gap-7 text-[13.5px] font-medium text-stone-600 lg:flex">
                <a
                    href="#how-it-works"
                    className="text-stone-600 transition-colors hover:text-ink"
                >
                    How it works
                </a>
                <a
                    href="#sources"
                    className="text-stone-600 transition-colors hover:text-ink"
                >
                    Sources
                </a>
                <a
                    href="#the-ai-bit"
                    className="text-stone-600 transition-colors hover:text-ink"
                >
                    The AI bit
                </a>
                <a
                    href="#privacy"
                    className="text-stone-600 transition-colors hover:text-ink"
                >
                    Privacy
                </a>
            </div>
            <div className="flex items-center gap-4">
                <Link
                    href={login()}
                    className="text-[13.5px] font-semibold text-ink hover:text-brand"
                >
                    Sign in
                </Link>
                <Link
                    href={register()}
                    className="inline-block -rotate-[1.5deg] rounded-[9px] border-2 border-ink bg-brand px-[18px] py-[9px] text-[13.5px] font-bold text-white shadow-[3px_3px_0_#1c1917] transition-[translate,box-shadow] duration-100 hover:-translate-x-px hover:-translate-y-px hover:shadow-[4px_4px_0_#1c1917]"
                >
                    Start dumping
                </Link>
            </div>
        </div>
    );
}

function Hero() {
    return (
        <div
            id="how-it-works"
            className="px-5 pt-16 pb-[72px] md:px-12"
            style={gridBg}
        >
            <div className="flex flex-col items-center gap-[18px] text-center">
                <div className="inline-flex items-center gap-[7px] rounded-full border-[1.5px] border-dashed border-ink/35 bg-white px-[14px] py-[6px] text-[12.5px] font-semibold text-stone-600">
                    <span className="size-[7px] shrink-0 rounded-full bg-brand" />
                    For UK healthcare providers — from Doctors to Allied Health
                    Professionals
                </div>
                <h1 className="max-w-[860px] font-display text-[52px] leading-none font-semibold tracking-[-0.02em] md:text-[74px]">
                    Dump it.
                    <br />
                    We'll sort it{' '}
                    <span className="font-display font-medium text-brand italic">
                        (with AI)
                    </span>
                    .
                </h1>
                <p className="max-w-[600px] text-lg leading-[1.55] text-pretty text-stone-600">
                    Forward emails, ramble voice notes, snap certificates. The
                    AI reads every one and hands back categorised,
                    appraisal-ready evidence.
                </p>
                <div className="mt-1.5 flex flex-col items-center gap-3.5 sm:flex-row">
                    <Link href={register()}>
                        <BrandButton variant="primary" tabIndex={-1}>
                            Start dumping — it's free
                        </BrandButton>
                    </Link>
                    <a href="#sources">
                        <BrandButton variant="secondary" tabIndex={-1}>
                            See how it works
                        </BrandButton>
                    </a>
                </div>
                <CaveatNote rotate={-1.5}>
                    no formatting. no folders. no guilt.
                </CaveatNote>
            </div>
            <HeroVisual />
        </div>
    );
}

function HeroVisual() {
    return (
        <div className="mx-auto mt-16 flex max-w-[1120px] flex-col items-center gap-12 lg:mt-[120px] lg:grid lg:grid-cols-[1fr_280px_1fr] lg:items-center lg:gap-0">
            {/* What you dump: scattered source cards */}
            <div className="relative h-[300px] w-full max-w-[430px]">
                <CaveatNote
                    rotate={-2}
                    className="absolute -top-8 left-0 text-[20px]"
                >
                    what you dump ↓
                </CaveatNote>
                <div className="absolute top-3.5 left-1.5 w-[225px] -rotate-[5deg] rounded-[9px] border-2 border-ink bg-white px-3.5 py-3 shadow-[4px_4px_0_rgba(28,25,23,.12)]">
                    <div className="mb-[3px] text-[9.5px] font-bold tracking-[0.1em] text-brand uppercase">
                        Forwarded email
                    </div>
                    <div className="text-[13px] leading-[1.3] font-semibold">
                        FW: fwd: fw: ALS cert (see attached??)
                    </div>
                </div>
                <div className="absolute top-[104px] left-[140px] z-2 flex rotate-4 items-center gap-[9px] rounded-full bg-ink px-[17px] py-2.5 text-paper shadow-[4px_4px_0_rgba(28,25,23,.12)]">
                    <div className="flex size-6 items-center justify-center rounded-full bg-brand">
                        <div className="ml-0.5 size-0 border-t-[4.5px] border-b-[4.5px] border-l-[7px] border-t-transparent border-b-transparent border-l-white" />
                    </div>
                    <div className="text-xs font-semibold">
                        "so basically the MDT today…"
                    </div>
                </div>
                <div className="absolute top-[172px] left-5 w-[200px] rotate-3 rounded-[9px] border-2 border-ink bg-white px-[13px] py-[11px] shadow-[4px_4px_0_rgba(28,25,23,.12)]">
                    <div className="flex items-center gap-2">
                        <div className="flex h-[30px] w-6 items-center justify-center rounded-[4px] border-[1.5px] border-brand bg-brand-tint text-[8px] font-bold text-brand">
                            PDF
                        </div>
                        <div>
                            <div className="text-xs leading-[1.25] font-semibold">
                                scan0042_final_v2.pdf
                            </div>
                            <div className="text-[10px] text-stone-500">
                                a certificate, probably
                            </div>
                        </div>
                    </div>
                </div>
                <div className="absolute top-[228px] left-[230px] w-[170px] -rotate-4 overflow-hidden rounded-[9px] border-2 border-ink bg-white shadow-[4px_4px_0_rgba(28,25,23,.12)]">
                    <div className="bg-brand px-[11px] py-1 text-[9.5px] font-bold tracking-[0.1em] text-white uppercase">
                        Calendar · every Thu
                    </div>
                    <div className="px-[11px] pt-2 pb-2.5">
                        <div className="text-[13px] font-semibold">
                            Lung MDT
                        </div>
                    </div>
                </div>
                <svg
                    width="70"
                    height="120"
                    viewBox="0 0 70 120"
                    className="absolute top-[90px] -right-4 hidden lg:block"
                >
                    <line
                        x1="4"
                        y1="20"
                        x2="60"
                        y2="55"
                        stroke="#a8a29e"
                        strokeWidth="2"
                        strokeDasharray="2 7"
                        strokeLinecap="round"
                    />
                    <line
                        x1="4"
                        y1="100"
                        x2="60"
                        y2="65"
                        stroke="#a8a29e"
                        strokeWidth="2"
                        strokeDasharray="2 7"
                        strokeLinecap="round"
                    />
                </svg>
            </div>

            {/* The AI reactor */}
            <div className="relative flex flex-col items-center justify-center">
                <div className="relative flex size-[190px] items-center justify-center">
                    <div className="absolute inset-0 rotate-8 rounded-full border-[2.5px] border-dashed border-brand/45" />
                    <div className="flex size-[158px] -rotate-2 flex-col items-center justify-center gap-[7px] rounded-full border-[3px] border-ink bg-ink shadow-[6px_6px_0_rgba(244,89,12,.4)]">
                        <Sparkle size={34} className="text-brand" />
                        <div className="text-center text-[19px] leading-[1.1] font-bold tracking-[-0.02em] text-paper">
                            AI
                            <br />
                            Analysis
                        </div>
                    </div>
                </div>
                <CaveatNote rotate={-1.5} className="mt-3">
                    reads · titles · scores · maps
                </CaveatNote>
                <svg
                    width="90"
                    height="24"
                    viewBox="0 0 90 24"
                    className="absolute top-[82px] -right-[52px] hidden lg:block"
                >
                    <line
                        x1="4"
                        y1="12"
                        x2="68"
                        y2="12"
                        stroke="#a8a29e"
                        strokeWidth="2"
                        strokeDasharray="2 7"
                        strokeLinecap="round"
                    />
                    <path
                        d="M68 4 L82 12 L68 20"
                        fill="none"
                        stroke="#f4590c"
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                </svg>
            </div>

            {/* What comes out */}
            <div className="relative w-full max-w-[430px] lg:pl-11">
                <CaveatNote
                    rotate={2}
                    color="brand"
                    className="absolute -top-[34px] right-0 text-[20px]"
                >
                    what comes out the other end ↓
                </CaveatNote>
                <OutputCard rotate={0.7} className="pt-3.5 pb-3.5">
                    <div className="mb-[7px] flex flex-wrap items-center gap-1.5">
                        <Chip>Course · 6 pts</Chip>
                        <Chip variant="dashed">Domain 1</Chip>
                        <Chip variant="dashed">Domain 3</Chip>
                    </div>
                    <div className="text-[14.5px] font-bold tracking-[-0.02em]">
                        Advanced Life Support — recertification
                    </div>
                    <div className="mt-0.5 text-[11px] text-stone-500">
                        12 Jun 2026 · Resuscitation Council UK · reflection
                        drafted
                    </div>
                    <div className="mt-2.5 flex items-center gap-2">
                        <span className="rounded-[7px] border-2 border-ink bg-brand px-3 py-[4.5px] text-[11px] font-bold text-white shadow-[2px_2px_0_#1c1917]">
                            Approve
                        </span>
                        <span className="rounded-[7px] border-2 border-ink bg-white px-2.5 py-[4.5px] text-[11px] font-semibold">
                            Edit
                        </span>
                    </div>
                </OutputCard>
                <OutputCard rotate={-0.9} className="mt-3">
                    <div className="mb-[5px] flex items-center gap-1.5">
                        <Chip>Reflection · 1 pt</Chip>
                        <Chip variant="dashed">Domain 2</Chip>
                    </div>
                    <div className="text-[13.5px] font-bold tracking-[-0.02em]">
                        MDT discussion — incidental nodule pathway
                    </div>
                    <div className="mt-0.5 text-[11px] text-stone-500">
                        from your voice note · today
                    </div>
                </OutputCard>
                <OutputCard rotate={0.5} className="mt-3">
                    <div className="mb-[5px] flex items-center gap-1.5">
                        <Chip>Meeting · 1 pt</Chip>
                        <Chip variant="dashed">Domain 1</Chip>
                    </div>
                    <div className="text-[13.5px] font-bold tracking-[-0.02em]">
                        Lung MDT — weekly attendance logged
                    </div>
                    <div className="mt-0.5 text-[11px] text-stone-500">
                        from your calendar · Thursday
                    </div>
                </OutputCard>
            </div>
        </div>
    );
}

function OutputCard({
    children,
    rotate,
    className,
}: {
    children: ReactNode;
    rotate: number;
    className?: string;
}) {
    return (
        <div
            style={{ rotate: `${rotate}deg` }}
            className={`w-full max-w-[340px] rounded-xl border-2 border-ink bg-white px-[18px] py-3 shadow-[5px_5px_0_rgba(28,25,23,.12)] ${className ?? ''}`}
        >
            {children}
        </div>
    );
}

const SOURCE_CARDS: { title: string; body: ReactNode; rotate: number }[] = [
    {
        title: 'Forwarded emails',
        body: (
            <>
                Send anything to{' '}
                <span className="rounded-[4px] bg-brand-tint px-[5px] py-px font-mono text-[11px] text-brand-dark">
                    you@in.cpddump.com
                </span>
            </>
        ),
        rotate: -0.7,
    },
    {
        title: 'Voice notes',
        body: 'Ramble on the drive home. The app transcribes and files it.',
        rotate: 0.6,
    },
    {
        title: 'Calendar events',
        body: 'MDTs, journal clubs, teaching — scraped weekly from Outlook, NHSmail or Google.',
        rotate: -0.5,
    },
    {
        title: 'PDFs & certificates',
        body: 'Drag them in. Points and provider extracted automatically.',
        rotate: 0.8,
    },
    {
        title: 'Photos & screenshots',
        body: 'Snap the certificate on the wall. Share straight from your phone.',
        rotate: -0.9,
    },
    {
        title: 'Links & articles',
        body: 'Paste a journal article. Get a summary and suggested reflection.',
        rotate: 0.5,
    },
];

function Sources() {
    return (
        <div
            id="sources"
            className="border-t border-dashed border-ink/18 px-5 pt-[72px] pb-[76px] md:px-12"
        >
            <div className="mx-auto grid max-w-[1080px] items-start gap-12 lg:grid-cols-[340px_1fr]">
                <div className="lg:sticky lg:top-8">
                    <CaveatNote
                        rotate={0}
                        color="brand"
                        className="text-[19px]"
                    >
                        feed it anything
                    </CaveatNote>
                    <h2 className="mt-1 font-display text-[40px] leading-[1.05] font-semibold tracking-[-0.01em]">
                        Lots of ways in.
                        <br />
                        All of them lazy.
                    </h2>
                    <p className="mt-3.5 text-[14.5px] leading-[1.6] text-pretty text-stone-600">
                        You get a personal dump address the day you register.
                        Everything else connects once and quietly feeds the
                        inbox.
                    </p>
                </div>
                <div className="grid grid-cols-1 gap-[18px] sm:grid-cols-2 xl:grid-cols-3">
                    {SOURCE_CARDS.map((card) => (
                        <div
                            key={card.title}
                            style={{ rotate: `${card.rotate}deg` }}
                            className="rounded-[11px] border-[1.5px] border-dashed border-stone-400 bg-white p-[18px]"
                        >
                            <div className="mb-[5px] text-[14.5px] font-bold">
                                {card.title}
                            </div>
                            <div className="text-[12.5px] leading-normal text-stone-500">
                                {card.body}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

const INBOX_ROWS = [
    {
        source: 'Voice',
        sourceHot: true,
        title: 'Reflection: the tricky nodule case from Tuesday’s list',
        titleNote: null,
        chip: 'Reflection · 1 pt',
        date: 'Today',
        highlight: true,
    },
    {
        source: 'Email',
        sourceHot: false,
        title: 'Certificate: Advanced Life Support recertification',
        titleNote: null,
        chip: 'Course · 6 pts',
        date: 'Yesterday',
        highlight: false,
    },
    {
        source: 'Calendar',
        sourceHot: false,
        title: 'Lung MDT',
        titleNote: '(recurring — ignore forever?)',
        chip: 'Meeting · 1 pt',
        date: 'Thu',
        highlight: false,
    },
    {
        source: 'Upload',
        sourceHot: false,
        title: 'RCR Annual Conference — programme + certificate',
        titleNote: null,
        chip: 'Conference · 12 pts',
        date: 'Mon',
        highlight: false,
    },
];

function InboxMockup() {
    return (
        <div className="border-t border-dashed border-ink/18 bg-paper-alt px-5 py-[70px] md:px-12">
            <div className="mx-auto max-w-[1080px]">
                <div className="mb-9 text-center">
                    <CaveatNote
                        rotate={1}
                        color="brand"
                        className="text-[19px]"
                    >
                        your actual homepage
                    </CaveatNote>
                    <h2 className="mt-1 font-display text-4xl font-semibold tracking-[-0.01em] md:text-[44px]">
                        An inbox, not a filing cabinet
                    </h2>
                </div>
                <div className="relative mx-auto max-w-[880px]">
                    <div className="-rotate-[0.6deg] overflow-hidden rounded-[14px] border-2 border-ink bg-white shadow-[6px_6px_0_rgba(28,25,23,.12)]">
                        <div className="flex items-center justify-between border-b border-ink/10 px-5 py-3.5">
                            <div className="flex items-center gap-2">
                                <StampLogo size="sm" />
                                <span className="text-[13px] font-bold">
                                    Inbox
                                </span>
                                <span className="rounded-full bg-brand px-2 py-0.5 text-[11px] font-bold text-white">
                                    4
                                </span>
                            </div>
                            <div className="flex items-center gap-2.5">
                                <div className="hidden w-[200px] rounded-lg border-[1.5px] border-ink/20 px-3 py-1.5 text-xs text-stone-400 md:block">
                                    Search your activities…
                                </div>
                                <div className="text-[11.5px] font-semibold text-stone-600">
                                    Appraisal year: 2025–26
                                </div>
                            </div>
                        </div>
                        <div className="flex gap-3.5 border-b border-ink/10 px-5 py-3 text-xs text-stone-600">
                            <span>
                                <strong className="text-ink">38</strong>{' '}
                                activities
                            </span>
                            <span>
                                <strong className="text-ink">52</strong> CPD
                                points
                            </span>
                            <span>
                                <strong className="text-brand">4</strong>{' '}
                                awaiting approval
                            </span>
                            <span className="ml-auto hidden text-stone-500 sm:inline">
                                Domain 2 looking thin —{' '}
                                <span className="font-semibold text-brand">
                                    see gaps
                                </span>
                            </span>
                        </div>
                        {INBOX_ROWS.map((row, i) => (
                            <div
                                key={row.title}
                                className={`flex items-center gap-3.5 px-5 py-[13px] ${i < INBOX_ROWS.length - 1 ? 'border-b border-ink/7' : ''} ${row.highlight ? 'bg-[#fffbf8]' : ''}`}
                            >
                                <span
                                    className={`w-[58px] shrink-0 text-[9.5px] font-bold tracking-[0.08em] uppercase ${row.sourceHot ? 'text-brand' : 'text-stone-500'}`}
                                >
                                    {row.source}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-[13px] font-semibold">
                                    {row.title}{' '}
                                    {row.titleNote && (
                                        <span className="font-normal text-stone-500">
                                            {row.titleNote}
                                        </span>
                                    )}
                                </span>
                                <span className="hidden rounded-full bg-brand-tint px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap text-brand-dark sm:inline">
                                    {row.chip}
                                </span>
                                <span className="hidden text-[11px] whitespace-nowrap text-stone-500 md:inline">
                                    {row.date}
                                </span>
                                <span className="rounded-[7px] border-[1.5px] border-ink bg-white px-[11px] py-1 text-[11.5px] font-bold whitespace-nowrap">
                                    Approve
                                </span>
                            </div>
                        ))}
                    </div>
                    <CaveatNote
                        rotate={4}
                        color="brand"
                        className="absolute -top-5 -right-1 text-[20px] md:-right-[26px]"
                    >
                        approve or bin.
                        <br />
                        that's the job.
                    </CaveatNote>
                </div>
            </div>
        </div>
    );
}

const TIMELINE_DOTS: { left: string; color: string; glow?: boolean }[] = [
    { left: '4%', color: '#f4590c' },
    { left: '13%', color: '#3f8fd2' },
    { left: '21%', color: '#2f9e64', glow: true },
    { left: '34%', color: '#f4590c' },
    { left: '42%', color: '#9a6fd0' },
    { left: '55%', color: '#3f8fd2' },
    { left: '63%', color: '#2f9e64' },
    { left: '76%', color: '#f4590c' },
    { left: '88%', color: '#9a6fd0' },
];

const TIMELINE_LEGEND = [
    { label: 'Courses', color: '#f4590c' },
    { label: 'Meetings & MDTs', color: '#3f8fd2' },
    { label: 'Teaching', color: '#2f9e64' },
    { label: 'QI & audit', color: '#9a6fd0' },
];

function Timeline() {
    return (
        <div className="border-t border-dashed border-ink/18 px-5 py-[70px] md:px-12">
            <div className="mx-auto max-w-[1080px]">
                <div className="mb-9 text-center">
                    <CaveatNote
                        rotate={-1}
                        color="brand"
                        className="text-[19px]"
                    >
                        where approved things go
                    </CaveatNote>
                    <h2 className="mt-1 font-display text-4xl font-semibold tracking-[-0.01em] md:text-[44px]">
                        A year of work, on one line
                    </h2>
                </div>
                <div className="relative mt-4 rotate-[0.5deg] rounded-[14px] border-2 border-ink bg-white px-4 py-6 shadow-[6px_6px_0_rgba(28,25,23,.12)] md:px-[34px]">
                    <div className="absolute top-5 left-4 z-2 w-[230px] -rotate-[1.5deg] rounded-[10px] bg-ink px-4 py-[13px] text-paper shadow-[4px_4px_0_rgba(28,25,23,.2)] md:left-[200px]">
                        <div className="text-[13px] font-bold">
                            FRCR physics teaching
                        </div>
                        <div className="mt-[3px] text-[11px] text-stone-400">
                            4 Mar · 2 CPD pts · Domain 1
                        </div>
                        <div className="mt-0.5 text-[11px] text-brand">
                            linked: FRCR Teaching project
                        </div>
                        <div className="absolute -bottom-[7px] left-10 size-3 rotate-45 bg-ink" />
                    </div>
                    <div className="relative mt-[110px] h-[110px] md:mt-11">
                        <div className="absolute top-[54px] right-0 left-0 border-t-2 border-dashed border-ink/25" />
                        {TIMELINE_DOTS.map((dot, i) => (
                            <div
                                key={i}
                                className="absolute top-[46px] size-4 rounded-full border-2 border-ink"
                                style={{
                                    left: dot.left,
                                    backgroundColor: dot.color,
                                    boxShadow: dot.glow
                                        ? '0 0 0 5px rgba(47,158,100,.2)'
                                        : undefined,
                                }}
                            />
                        ))}
                        <div className="absolute top-[86px] left-0 text-[11px] font-semibold text-stone-500">
                            Apr '25
                        </div>
                        <div className="absolute top-[86px] left-[48%] text-[11px] font-semibold text-stone-500">
                            Oct
                        </div>
                        <div className="absolute top-[86px] right-0 text-[11px] font-semibold text-stone-500">
                            Mar '26
                        </div>
                    </div>
                    <div className="flex flex-wrap justify-center gap-x-5 gap-y-2 border-t border-ink/8 pt-4 text-[11.5px] font-semibold text-stone-600">
                        {TIMELINE_LEGEND.map((item) => (
                            <span
                                key={item.label}
                                className="flex items-center gap-1.5"
                            >
                                <span
                                    className="size-2.5 rounded-full"
                                    style={{ backgroundColor: item.color }}
                                />
                                {item.label}
                            </span>
                        ))}
                    </div>
                </div>
                <p className="mt-[18px] text-center text-[13.5px] text-pretty text-stone-500">
                    Appraisal done? Reset the window. Old years stay safe —
                    scroll back any time.
                </p>
            </div>
        </div>
    );
}

const AI_BULLETS = [
    'Identifies activity type, dates & organisations',
    'Estimates CPD points — one per hour, roughly',
    'Maps to GMC Good Medical Practice domains',
    'Drafts reflections that sound human, not robotic',
    'Links activities to your PDP objectives & projects',
    'Spots duplicates and missing evidence',
];

function AiSection() {
    return (
        <div
            id="the-ai-bit"
            className="bg-ink px-5 py-[70px] text-paper md:px-12"
        >
            <div className="mx-auto grid max-w-[1080px] items-center gap-10 lg:grid-cols-2 lg:gap-14">
                <div>
                    <CaveatNote
                        rotate={0}
                        color="brand"
                        className="text-[19px]"
                    >
                        the AI bit
                    </CaveatNote>
                    <h2 className="mt-1 font-display text-4xl leading-[1.1] font-semibold tracking-[-0.01em] md:text-[44px]">
                        It does the boring part. You get the credit.
                    </h2>
                    <p className="mt-3.5 text-[15px] leading-[1.6] text-pretty text-stone-400">
                        Every dumped item gets read, titled, dated and scored.
                        Reflections come out sounding like you wrote them —
                        because you approve every word before it goes anywhere.
                    </p>
                    <div className="mt-4 inline-block rounded-[9px] border-[1.5px] border-dashed border-paper/25 px-3.5 py-2.5 text-[12.5px] text-stone-500">
                        Bring your own OpenAI or Anthropic key — or just use
                        ours.
                    </div>
                </div>
                <div className="flex flex-col gap-[11px]">
                    {AI_BULLETS.map((bullet) => (
                        <div
                            key={bullet}
                            className="flex items-baseline gap-[11px]"
                        >
                            <span className="font-bold text-brand">✓</span>
                            <span className="text-[14.5px]">{bullet}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

const TESTIMONIALS = [
    {
        quote: '“My appraisal prep took twenty minutes. Twenty.”',
        who: 'Dr Placeholder · Consultant Radiologist',
        rotate: -1,
    },
    {
        quote: '“I ramble into my phone in the car park. It becomes a reflection. Witchcraft.”',
        who: 'Dr Placeholder · GP Partner',
        rotate: 0.8,
    },
    {
        quote: '“Finally — a portfolio tool that admits nobody wants to use a portfolio tool.”',
        who: 'Dr Placeholder · ST5 Anaesthetics',
        rotate: -0.7,
    },
];

function Testimonials() {
    return (
        <div className="border-t border-dashed border-ink/18 px-5 py-[70px] md:px-12">
            <div className="mx-auto max-w-[1080px]">
                <div className="mb-[38px] text-center">
                    <h2 className="font-display text-4xl font-semibold tracking-[-0.01em] md:text-[44px]">
                        From people who would rather be doing anything else…
                    </h2>
                    <CaveatNote rotate={0} className="mt-1">
                        (placeholder quotes — real ones after beta)
                    </CaveatNote>
                </div>
                <div className="grid gap-6 md:grid-cols-3">
                    {TESTIMONIALS.map((t) => (
                        <div
                            key={t.who + t.quote}
                            style={{ rotate: `${t.rotate}deg` }}
                            className="rounded-xl border-2 border-ink bg-white p-6 shadow-[4px_4px_0_rgba(28,25,23,.1)]"
                        >
                            <div className="font-quote text-lg leading-[1.45] italic">
                                {t.quote}
                            </div>
                            <div className="mt-3.5 text-[12.5px] text-stone-500">
                                {t.who}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function PrivacyBanner() {
    return (
        <div
            id="privacy"
            className="mx-auto max-w-[1080px] px-5 pb-[70px] md:px-12"
        >
            <div className="flex -rotate-[0.4deg] flex-col items-start gap-[22px] rounded-[14px] border-[2.5px] border-brand bg-brand-pale px-6 py-7 md:flex-row md:items-center md:px-[34px]">
                <div className="flex size-[46px] flex-none items-center justify-center rounded-full border-[2.5px] border-brand text-2xl font-bold text-brand">
                    !
                </div>
                <div>
                    <div className="text-[19px] font-bold tracking-[-0.02em]">
                        No patient data. Ever.
                    </div>
                    <p className="mt-[5px] text-sm leading-[1.55] text-pretty text-stone-600">
                        Nothing you upload should identify a patient, colleague
                        or anyone else. We scan for identifiers and warn you
                        before anything is stored. Uploads are encrypted, raw
                        email content is minimised, and nothing AI writes goes
                        anywhere without your approval.
                    </p>
                </div>
                <Link
                    href="/privacy"
                    className="flex-none border-b-[1.5px] border-brand pb-px text-[13px] font-semibold text-brand md:ml-auto"
                >
                    Privacy policy
                </Link>
            </div>
        </div>
    );
}

function FinalCta() {
    return (
        <div
            className="border-t border-dashed border-ink/18 px-5 py-16 text-center md:px-12"
            style={gridBg}
        >
            <h2 className="font-display text-4xl font-semibold tracking-[-0.01em] md:text-[52px]">
                Your CPD is already piling up.
            </h2>
            <p className="mt-2 text-base text-stone-600">
                Might as well pile it somewhere useful.
            </p>
            <div className="mt-[22px]">
                <Link href={register()}>
                    <BrandButton
                        variant="primary"
                        className="px-[30px] py-3.5 text-base"
                        tabIndex={-1}
                    >
                        Start dumping — free in beta
                    </BrandButton>
                </Link>
            </div>
        </div>
    );
}

function Footer() {
    return (
        <div className="flex flex-col items-center justify-between gap-4 border-t border-ink/12 px-5 py-[26px] text-[12.5px] text-stone-500 md:flex-row md:px-12">
            <div className="flex items-center gap-2.5">
                <span className="inline-block -rotate-4 rounded-[7px] border-2 border-brand p-0.5">
                    <span className="inline-block rounded-[4px] border border-brand px-[7px] py-px text-[10.5px] font-bold tracking-[0.05em] text-brand uppercase">
                        CPD Dump
                    </span>
                </span>
                <span>
                    · made for doctors who'd rather be doing anything else
                </span>
            </div>
            <div className="flex flex-wrap justify-center gap-x-[22px] gap-y-2">
                <a
                    href="#how-it-works"
                    className="text-stone-500 hover:text-ink"
                >
                    How it works
                </a>
                <Link href="/privacy" className="text-stone-500 hover:text-ink">
                    Privacy policy
                </Link>
                <Link href="/terms" className="text-stone-500 hover:text-ink">
                    Terms
                </Link>
                <Link href={login()} className="text-stone-500 hover:text-ink">
                    Sign in
                </Link>
            </div>
        </div>
    );
}
