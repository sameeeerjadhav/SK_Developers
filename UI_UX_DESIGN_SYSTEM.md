# SK Mobility — UI / UX Design System

Use this document as the **single source of truth** when building a new project that should look and feel like SK Mobility. Prefer these tokens and patterns over inventing new colors, fonts, or layout conventions.

**Vibe:** Calm EV / dealership ERP — soft teal atmosphere, white cards, tight typography, light motion. Not purple SaaS, not dark mode, not heavy shadows.

---

## 1. Design principles

1. **Teal-first brand** — Primary accent is teal (`#0d9488`). Almost all interactive emphasis uses this family.
2. **Soft atmosphere, not flat white** — Page background is a cool mint wash with subtle radial gradients. Cards sit on white.
3. **Glass / blur chrome** — Sidebar and topbar use frosted white (`rgba(255,255,255,0.82–0.92)` + `backdrop-filter: blur`).
4. **Calm density** — Comfortable padding, 12–16px radii, thin mint borders. Avoid harsh black borders.
5. **Weight through type** — Titles are extra-bold (700–800) with negative letter-spacing. Labels are small, uppercase, muted.
6. **Light motion only** — Short fades / slide-ups (`0.15–0.35s`). Hover lifts cards by ~2px. No bounce, no neon glow.
7. **One visual language everywhere** — Same tokens for admin app, forms, tables, login, and marketing-adjacent screens.

---

## 2. CSS custom properties (copy as-is)

```css
:root {
  --primary: #0d9488;
  --primary-dark: #0f766e;
  --primary-light: #14b8a6;
  --primary-soft: #ccfbf1;
  --primary-ghost: rgba(13, 148, 136, 0.08);

  --bg: #edf7f5;
  --bg-deep: #d8efe9;
  --card: #ffffff;
  --border: #e2efec;
  --border-strong: #cbddd8;
  --text: #0b1f1c;
  --muted: #5b746f;

  --danger: #dc2626;
  --warning: #d97706;
  --success: #059669;
  --info: #0284c7;

  --shadow-sm: 0 1px 2px rgba(11, 31, 28, 0.04);
  --shadow-md: 0 8px 24px rgba(11, 31, 28, 0.06);
  --shadow-lg: 0 20px 50px rgba(11, 31, 28, 0.1);

  --radius: 16px;
  --radius-sm: 12px;
  --sidebar-w: 260px;

  --font: "Plus Jakarta Sans", system-ui, sans-serif;
  --ease: cubic-bezier(0.22, 1, 0.36, 1);
}
```

### Color usage map

| Token | Use for |
|--------|---------|
| `--primary` | Links, active tabs, focus rings, accents |
| `--primary-dark` | Active nav text, kicker labels, hover link |
| `--primary-light` | Gradients start, brand mark |
| `--primary-soft` | Active nav fill, primary chips |
| `--primary-ghost` | Hover backgrounds |
| `--bg` / page gradient | App canvas behind cards |
| `--card` | Cards, modals, panels |
| `--border` | Card / sidebar / table borders |
| `--border-strong` | Inputs, outline buttons |
| `--text` | Headings, body |
| `--muted` | Subtitles, table headers, helper text |
| Status colors | Chips / alerts only — do not recolor the whole UI |

### Forbidden defaults

- Do **not** use Inter / Roboto / Arial as the primary UI font.
- Do **not** default to purple / indigo SaaS themes.
- Do **not** use pure black (`#000`) for text or borders.
- Do **not** use large multi-layer dramatic shadows or glow effects.
- Prefer light mode. Dark mode is out of scope unless explicitly requested.

---

## 3. Typography

### Fonts

| Role | Family | Weights | Where |
|------|--------|---------|--------|
| App UI | **Plus Jakarta Sans** | 400, 500, 600, 700, 800 | Entire authenticated app |
| Display / login brand | **Sora** (fallback: Plus Jakarta Sans) | 600, 700, 800 | Login hero brand + panel title |

Google Fonts load example:

```
Plus+Jakarta+Sans:wght@400;500;600;700;800
Sora:wght@600;700;800   /* guest/login only */
```

### Type scale & style

| Element | Size | Weight | Notes |
|---------|------|--------|-------|
| Page title | `clamp(1.5rem, 2.2vw, 1.85rem)` | 800 | `letter-spacing: -0.035em` |
| Page subtitle | `0.95rem` | 500 | `--muted` |
| Card title | `1rem` | 800 | `letter-spacing: -0.02em` |
| Body / table | `0.875–0.9rem` | 400–600 | |
| Form labels | `0.78rem` | 700 | Color `#3d5550` |
| Nav section | `0.65rem` | 700 | Uppercase, `letter-spacing: 0.08em` |
| Table header | `0.72rem` | 700 | Uppercase, muted |
| Stat label | `0.75rem` | 700 | Uppercase |
| Stat value | `clamp(1rem, 1.6vw, 1.35rem)` | 800 | Tight tracking |
| Login brand | `clamp(2.6rem, 6vw, 4.4rem)` | 800 | Sora, `letter-spacing: -0.05em` |
| Login kicker | `0.78rem` | 700 | Uppercase, `letter-spacing: 0.14em`, primary-dark |

---

## 4. Page atmosphere (app shell)

```css
body {
  font-family: var(--font);
  color: var(--text);
  background:
    radial-gradient(1200px 600px at 0% -10%, rgba(13, 148, 136, 0.14), transparent 55%),
    radial-gradient(900px 500px at 100% 0%, rgba(20, 184, 166, 0.1), transparent 50%),
    linear-gradient(180deg, #f4fbf9 0%, var(--bg) 40%, #e8f4f1 100%);
  background-attachment: fixed;
}
```

Content area padding: ~`1.6rem` (reduce on mobile).

---

## 5. Layout structure

```
┌────────────┬─────────────────────────────┐
│  Sidebar   │  Topbar (sticky, glass)     │
│  260px     ├─────────────────────────────┤
│  fixed     │  Content                    │
│  glass     │  page-title + page-sub      │
│            │  cards / grids / tables     │
└────────────┴─────────────────────────────┘
```

### Sidebar

- Width: `260px`, fixed left.
- Background: `rgba(255,255,255,0.92)` + blur `12px`.
- Border-right: `1px solid var(--border)`.
- **Brand mark:** 38×38, radius 12, gradient `primary-light → primary-dark`, white initials, soft teal shadow.
- **Nav sections:** tiny uppercase muted labels.
- **Nav links:** radius 12, muted green-gray text (`#3d5550`).
  - Hover: `--primary-ghost` bg.
  - Active: soft teal gradient fill + **inset left bar** `3px solid --primary`.
- Logout: separate danger-tinted style (light red border/bg).

### Topbar

- Sticky, glass (`rgba(255,255,255,0.82)` + blur `14px`).
- Search input: mint-tinted bg, radius 14, focus ring `0 0 0 4px rgba(13,148,136,0.12)`.
- Icon buttons: 42×42, radius 14, white + border; slight lift on hover.
- User pill: rounded-full white chip with gradient avatar.

### Mobile

- Sidebar off-canvas; dark teal-tinted overlay `rgba(11,31,28,0.4)`.
- Collapse grids to 1 column under ~900px.

---

## 6. Components

### Cards

- White, `1px` border `--border`, radius `--radius` (16px), padding `~1.3rem`, `--shadow-sm`.
- Hover: `--shadow-md` (no strong scale).
- Stacked sibling cards: `margin-top: 1rem`.

### Stat cards

- Same as cards, with a **3px left accent** gradient (`primary-light → primary-dark`).
- Staggered `fadeUp` entrance (delays ~0.02–0.26s).
- Hover: `translateY(-2px)`.

### Buttons

| Variant | Style |
|---------|--------|
| Primary | Gradient `primary-light → primary`, white text, teal glow shadow |
| Outline | White + `--border-strong`; hover tint with primary |
| Danger | Soft red bg (`#fee2e2`), danger text |
| Small | Tighter padding, radius 10 |

- Radius: 12px. Weight: 700. Active press: `scale(0.98)`.

### Forms

- 2-column `.form-grid` with `.full` spanning both.
- Labels above fields, small and bold.
- Inputs: radius 12, `--border-strong`, focus = primary border + 4px teal ring.
- Highlight boxes (e.g. sell amount): soft green panel `#f0fdf4` / border `#86efac`.

### Tables (`.data`)

- Header row: uppercase muted, bg `#f7fcfb`.
- Row hover: `#f4fbf9`.
- No heavy zebra; rely on soft hover.

### Chips / status

Pill chips, tiny bold text:

- warning: `#fef3c7` / `#b45309`
- success: `#d1fae5` / `#047857`
- danger: `#fee2e2` / `#b91c1c`
- info: `#e0f2fe` / `#0369a1`
- primary: `--primary-soft` / `--primary-dark`

### Tabs

- Pill container (rounded-full) with soft white bg + border.
- Active tab: solid `--primary`, white text, light teal shadow.

### Alerts

Rounded 14px, tinted bg + matching border:

- success / error / info — soft pastels, bold text.

### Modals

- Backdrop: `rgba(11,31,28,0.48)` + blur.
- Panel: white, radius 20, max-width ~640 (lg ~880), `--shadow-lg`.

### Product / vehicle cards

- Clean white tile, image area with mint→white gradient, subtle hover lift + teal-ish border `#b6e4de`.

---

## 7. Login / guest screen

Split composition (desktop): hero copy left, frosted sign-in panel right.

### Atmosphere

- Mint linear gradient background.
- Soft blurred teal orbs (slow drift animation).
- Faint grid + dashed “road” line near bottom (subtle motion).

### Hero

- Kicker (uppercase primary-dark) → giant **Sora** brand → short tagline → 3 feature bullets with small teal square markers.
- Brand is the dominant visual; do not bury the product name.

### Panel

- Frosted white glass, radius ~22, soft shadow.
- Title in Sora; calm subtitle; standard form; full-width primary CTA.

### Mobile

- Stack to one column; keep brand first, then form.

---

## 8. Motion

```css
--ease: cubic-bezier(0.22, 1, 0.36, 1);

/* Prefer these */
@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
@keyframes slideInLeft { from { opacity:0; transform:translateX(-12px); } to { opacity:1; transform:none; } }
```

Guidelines:

- Content enter: `fadeUp` ~0.35s.
- Hover transitions: 0.12–0.2s.
- Login: slightly longer reveal (~0.55–0.7s) + ambient orb/road loops.
- Prefer 2–3 intentional motions per screen, not decoration everywhere.

---

## 9. Spacing rhythm

Aim for **tight, even section spacing**:

| Context | Gap / margin |
|---------|----------------|
| Stat / card grids | `0.9–1rem` |
| Form grid | `1rem` |
| Between major page blocks | `1–1.35rem` |
| Content padding | `1.6rem` |
| Card internal title → body | `1rem` |

Avoid mixing large custom margins (e.g. 2.5rem then 0.5rem) between sibling sections — keep one consistent step.

---

## 10. Iconography & imagery

- Inline SVG icons ~18px in nav; muted opacity, full on hover/active.
- Brand / avatar marks use the same teal gradient as primary buttons.
- Prefer real product imagery (vehicles, parts) over abstract purple gradients.
- Empty states: mint-tinted, primary-dark text, not gray void.

---

## 11. Do / Don’t checklist for implementers

**Do**

- Start every new screen from the CSS variables above.
- Use Plus Jakarta Sans for app chrome; Sora for large brand moments.
- Put primary actions in teal gradient buttons.
- Use soft mint borders and backgrounds for structure.
- Keep focus rings teal and visible.

**Don’t**

- Introduce a second accent color family (purple, orange brand, etc.) without a strong reason.
- Build “dashboard soup” on marketing/login heroes (no stats strips, pill clusters, floating badges on hero art).
- Use heavy card nesting or borders-for-everything; cards are for interactive/content containers.
- Copy Material / generic Bootstrap look; this system is custom teal ERP.

---

## 12. Minimal starter snippet

Paste into a new project to lock the look early:

```html
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --primary:#0d9488; --primary-dark:#0f766e; --primary-light:#14b8a6;
  --primary-soft:#ccfbf1; --primary-ghost:rgba(13,148,136,.08);
  --bg:#edf7f5; --card:#fff; --border:#e2efec; --border-strong:#cbddd8;
  --text:#0b1f1c; --muted:#5b746f; --radius:16px;
  --font:"Plus Jakarta Sans",system-ui,sans-serif;
  --ease:cubic-bezier(.22,1,.36,1);
  --shadow-sm:0 1px 2px rgba(11,31,28,.04);
  --shadow-md:0 8px 24px rgba(11,31,28,.06);
}
body {
  margin:0; font-family:var(--font); color:var(--text);
  background:
    radial-gradient(1200px 600px at 0% -10%, rgba(13,148,136,.14), transparent 55%),
    radial-gradient(900px 500px at 100% 0%, rgba(20,184,166,.1), transparent 50%),
    linear-gradient(180deg,#f4fbf9 0%,var(--bg) 40%,#e8f4f1 100%);
}
.card {
  background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
  padding:1.3rem; box-shadow:var(--shadow-sm);
}
.btn-primary {
  border:0; border-radius:12px; padding:.6rem 1.05rem; font-weight:700; color:#fff; cursor:pointer;
  background:linear-gradient(145deg,var(--primary-light),var(--primary));
  box-shadow:0 6px 16px rgba(13,148,136,.28);
}
</style>
```

---

## 13. Reference in this repo

| File | What to mirror |
|------|----------------|
| `public/assets/css/app.css` | Full token set + all components |
| `app/Views/layouts/app.php` | Authenticated shell + Plus Jakarta Sans |
| `app/Views/layouts/guest.php` | Login fonts (Jakarta + Sora) |
| `app/Views/auth/login.php` | Login layout / brand hierarchy |
| `app/Views/dashboard/admin.php` | Stats + cards + grids pattern |

When another IDE builds a similar product, instruct it:

> Follow `docs/UI_UX_DESIGN_SYSTEM.md` exactly for colors, typography, radii, shadows, shell layout, and component styles. Match the teal mint ERP aesthetic; do not invent a new palette.
