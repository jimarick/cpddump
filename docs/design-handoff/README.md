# Handoff: CPD Dump — Marketing Homepage & Brand

## Overview
CPD Dump is an AI-powered "evidence inbox" for UK healthcare professionals: users dump CPD evidence (forwarded emails, voice notes, calendar events, PDFs, photos), AI analyses each item (title, dates, CPD points, GMC domains, drafted reflection), the user approves, and approved activities land on an appraisal-year timeline. This package contains the approved marketing homepage design and brand direction.

## About the Design Files
The files in this bundle are **design references created in HTML** — prototypes showing intended look and behaviour, **not production code to copy directly**. The task is to recreate this design in the target codebase's environment — for this project: **Laravel + React + Inertia** (Tailwind CSS if present) — using its established patterns. All styles in the reference are inline; translate them to the codebase's styling system.

## Fidelity
**High-fidelity.** Colors, typography, spacing, copy and layout are final and should be recreated pixel-faithfully. The app-UI mockups *inside* the page (inbox screenshot, timeline card, hero activity cards) are illustrative marketing imagery — reproduce them as shown; they are not specs for the real app screens.

## Brand

### Logo — the orange full stop (see brand.html)
Pure typographic wordmark, no icon:
- "cpd dump." lowercase, Bricolage Grotesque 800, letter-spacing -0.03em, ink #1C1917, with the full stop in orange #F4590C
- Nav size 20px; footer 14px
- Compact/monogram: "d." (same styling) — used on the inbox mockup header
- On dark (#1C1917): wordmark #FAF9F6, stop stays #F4590C

### App icon (see brand.html)
"CPD." on a paper tile:
- Tile: #FAF9F6, 1px border rgba(28,25,23,.14), radius ≈ 24% of tile size (26px at 110px)
- Text: "CPD" Bricolage Grotesque 800, letter-spacing -0.03em, ink #1C1917, orange full stop; ≈ 28% of tile height
- At ≤32px: fall back to "d." with the orange stop

### Visual motifs (used everywhere)
- Paper & ink: warm off-white surfaces, near-black ink borders (2px solid #1C1917)
- Hard offset shadows, never blurred: `3px 3px 0` / `4px 4px 0` #1C1917 on buttons; `rgba(28,25,23,.12)` on cards
- Everything sits slightly askew: cards/buttons rotated between -7° and +8°
- Dashed borders (1.5px dashed #A8A29E) for secondary chips/cards; dashed section dividers `1px dashed rgba(28,25,23,.18)`
- Grid-square background on hero and final CTA: two linear-gradients, `rgba(28,25,23,.045)` 1px lines, 52px cell
- Hand-scrawled annotations in Caveat, rotated 1–4°, gray #78716C or orange #F4590C
- AI is always marked with a 4-point diamond sparkle (SVG path: `M10 1 L12.2 7.8 L19 10 L12.2 12.2 L10 19 L7.8 12.2 L1 10 L7.8 7.8 Z`, fill #F4590C)

## Design Tokens

### Colors
- Paper (page bg): #FAF9F6
- Paper alt (section bg): #F5F3EE
- Ink (text, borders, dark surfaces): #1C1917
- Gray 700 (body copy): #57534E
- Gray 500 (muted): #78716C
- Gray 400 (dashed borders, lines): #A8A29E
- Orange (accent/brand): #F4590C
- Orange tint (chip bg): #FDE8DC
- Orange dark (chip text): #C2410C
- Orange pale (privacy banner bg): #FFF7F2
- Timeline category colors: courses #F4590C, meetings/MDTs #3F8FD2, teaching #2F9E64, QI & audit #9A6FD0

### Typography
- **Headings**: Bricolage Grotesque, weight 800 (Google Fonts). H1 60px/1.04, letter-spacing -0.03em; section titles 35px, letter-spacing -0.025em; "Lots of ways in" 32px/1.08; final CTA 42px
- **Body/UI**: Instrument Sans 400–700. Hero sub 18px/1.55; body 13.5–15px; nav links 13.5px/500
- **Annotations**: Caveat, 17–20px, rotated
- **Quotes**: Instrument Serif italic 18px/1.45 (testimonials, reflection excerpts)
- Accent inside H1: "(with AI)" in Bricolage Grotesque 800, #F4590C

### Buttons
All button labels in Bricolage Grotesque 600–700.
- Primary: bg #F4590C, white 700 text, 2px solid #1C1917 border, radius 10px, shadow `4px 4px 0 #1C1917`, rotated ~-1°, padding 13px 26px
- Secondary: bg #FFF, ink text 600, 2px solid #1C1917, radius 10px, no shadow, rotated ~+0.8°
- Hover (suggest): translate(-1px,-1px) with shadow growing to 5px 5px 0; active: translate(2px,2px), shadow 2px 2px 0

## Page Structure (top to bottom)

1. **Nav** — "cpd dump." wordmark left; center links: How it works, Sources, The AI bit, Privacy; right: "Sign in" + primary button "Start dumping". Bottom border 1px dashed.
2. **Hero** (grid-square bg) — dashed pill badge "For UK healthcare providers — from Doctors to Allied Health Professionals" with orange dot; H1 "Dump it. / We'll sort it *(with AI)*." (note: no "Four steps" section — it was removed); sub "Forward emails, ramble voice notes, snap certificates. The AI reads every one and hands back categorised, appraisal-ready evidence."; buttons "Start dumping — it's free" + "See how it works"; Caveat note "no formatting. no folders. no guilt."
3. **Hero visual** (120px below CTAs; 3-column grid 1fr/280px/1fr, max-width 1120px)
   - Left: 4 scattered rotated source cards (forwarded email, voice-note pill with waveform, calendar event "Lung MDT", PDF card) + Caveat label "what you dump ↓"; dashed lines (stroke #A8A29E, dasharray 2 7) converge rightward
   - Center: **AI Analysis reactor** — 158px ink circle (rotated -2°) with orange sparkle + "AI Analysis" in white, orange offset shadow `6px 6px 0 rgba(244,89,12,.4)`, surrounded by a 190px dashed orange orbit ring (rotated 8°); Caveat caption "reads · titles · scores · maps"; dashed arrow (orange head) exits right
   - Right: 3 compact output activity cards (340px) with category chips (orange tint) + dashed domain chips, title, meta line, first card has Approve/Edit buttons; Caveat label "what comes out the other end ↓"
4. **Sources** — left column (sticky): Caveat "feed it anything", title "Lots of ways in. / All of them lazy.", intro para mentioning the personal dump address; right: 3×2 grid of dashed-border cards: Forwarded emails (with mono address chip `you@in.cpddump.com`), Voice notes, Calendar events, PDFs & certificates, Photos & screenshots, Links & articles.
5. **Inbox mockup** (bg #F5F3EE) — Caveat "your actual homepage", title "An inbox, not a filing cabinet"; browser-card mock (rotated -0.6°) with: header ("d." monogram, "Inbox" + count badge, search field, "Appraisal year: 2025–26"), stats strip (38 activities · 52 CPD points · 4 awaiting approval · "Domain 2 looking thin — see gaps"), 4 inbox rows (source tag, title, category chip, date, Approve button; first row highlighted #FFFBF8); Caveat annotation top-right "approve or bin. that's the job."
6. **Timeline** — Caveat "where approved things go", title "A year of work, on one line"; white card with dark tooltip (pointer touching the highlighted green dot: "FRCR physics teaching / 4 Mar · 2 CPD pts · Domain 1 / linked: FRCR Teaching project"), dashed horizontal track with 9 colored dots (Apr '25 → Mar '26), color legend; caption about resetting the appraisal window.
7. **AI section** (dark, bg #1C1917) — two columns: left title "It does the boring part. You get the credit." + para + dashed chip "Bring your own OpenAI or Anthropic key — or just use ours."; right: 6 orange-check bullet points.
8. **Testimonials** — title "From people who would rather be doing anything else…" + Caveat "(placeholder quotes — real ones after beta)"; 3 rotated quote cards, Instrument Serif italic quotes, attribution "Dr Placeholder · specialty". **Placeholder content — replace with real quotes.**
9. **Privacy banner** — orange-bordered (2.5px #F4590C) card on #FFF7F2, "!" roundel, heading "No patient data. Ever.", copy re identifier scanning/encryption/approval, "Privacy policy" link.
10. **Final CTA** (grid bg) — "Your CPD is already piling up." / "Might as well pile it somewhere useful." + primary button "Start dumping — free in beta".
11. **Footer** — small "cpd dump." wordmark + "· made for doctors who'd rather be doing anything else"; links: How it works, Privacy policy, Terms, Sign in.

## Interactions & Behavior
- Nav: sticky optional; links smooth-scroll to sections ("Sources" → §4, "The AI bit" → §7, "Privacy" → §9)
- "Start dumping" → registration; "Sign in" → login; "See how it works" → scroll to hero visual or §4
- Suggested (not in reference): fade/slide-in of hero source cards; subtle rotation-to-0 on card hover; dashed-line "marching ants" animation toward the reactor. Keep motion subtle
- All rotations via `transform: rotate(...)` — keep them on hover states too (or ease to 0°)
- Responsive: mobile-first per project spec. Below ~900px stack the hero visual vertically (sources → reactor → outputs), sources grid 2-col then 1-col, inbox mock scrolls horizontally or simplifies to 3 rows, AI section stacks

## State Management
Static marketing page — no app state. Only nav scroll behavior and CTA routing (Inertia links to /register, /login).

## Assets
- No image assets. Everything is CSS/HTML + two inline SVG primitives: the diamond sparkle (path above) and dashed connector lines/arrows (plain SVG `<line>`/`<path>` elements)
- Fonts from Google Fonts: Bricolage Grotesque (400–800), Instrument Sans (400–700), Instrument Serif (italic), Caveat (400–700)

## Files
- `homepage.html` — the complete approved homepage design (self-contained; open in a browser). All measurements, colors and copy in this README come from this file; when in doubt, the file wins.
- `brand.html` — brand reference: wordmark (light/dark/compact), app icon at 3 sizes, heading/button type specimens.
