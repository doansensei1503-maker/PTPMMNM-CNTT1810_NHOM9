---
name: WebArchive Workspace
colors:
  surface: '#f8f9ff'
  surface-dim: '#cedbee'
  surface-bright: '#f8f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#eef4ff'
  surface-container: '#e4efff'
  surface-container-high: '#dce9fc'
  surface-container-highest: '#d6e4f7'
  on-surface: '#0f1d2a'
  on-surface-variant: '#43474d'
  inverse-surface: '#253140'
  inverse-on-surface: '#e9f1ff'
  outline: '#74777e'
  outline-variant: '#c3c6ce'
  surface-tint: '#48607e'
  primary: '#001a32'
  on-primary: '#ffffff'
  primary-container: '#152f4a'
  on-primary-container: '#7f97b7'
  inverse-primary: '#b0c8ea'
  secondary: '#3f6186'
  on-secondary: '#ffffff'
  secondary-container: '#b3d4ff'
  on-secondary-container: '#3a5c81'
  tertiary: '#001d1a'
  on-tertiary: '#ffffff'
  tertiary-container: '#00342f'
  on-tertiary-container: '#35a59a'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d2e4ff'
  primary-fixed-dim: '#b0c8ea'
  on-primary-fixed: '#001d36'
  on-primary-fixed-variant: '#304865'
  secondary-fixed: '#d1e4ff'
  secondary-fixed-dim: '#a8c9f4'
  on-secondary-fixed: '#001d36'
  on-secondary-fixed-variant: '#26496d'
  tertiary-fixed: '#8cf4e7'
  tertiary-fixed-dim: '#6fd8cb'
  on-tertiary-fixed: '#00201d'
  on-tertiary-fixed-variant: '#00504a'
  background: '#f8f9ff'
  on-background: '#0f1d2a'
  surface-variant: '#d6e4f7'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  display-lg-mobile:
    fontFamily: Inter
    fontSize: 26px
    fontWeight: '700'
    lineHeight: 34px
    letterSpacing: -0.015em
  headline-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.015em
  headline-sm:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 26px
    letterSpacing: -0.01em
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 22px
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.02em
  label-sm:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '500'
    lineHeight: 14px
    letterSpacing: 0.03em
  reader-title:
    fontFamily: Merriweather
    fontSize: 34px
    fontWeight: '700'
    lineHeight: 44px
    letterSpacing: -0.01em
  reader-title-mobile:
    fontFamily: Merriweather
    fontSize: 26px
    fontWeight: '700'
    lineHeight: 36px
  reader-body:
    fontFamily: Merriweather
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 32px
  reader-body-mobile:
    fontFamily: Merriweather
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 28px
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  gutter: 1.5rem
  gutter-mobile: 1rem
  margin: 2rem
  margin-mobile: 1rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2.5rem
---

## Brand & Style

This design system balances the structural clarity of an archival productivity SaaS (60%) with the deliberate tactile calm of an editorial reading sanctum (40%). It targets researchers, writers, archivists, and knowledge professionals who curate and deeply consume digital articles without algorithmic noise.

The aesthetic blends Modern Corporate SaaS with quiet Editorial refinement:
- **Calm, High-Utility Architecture:** Clean layouts, structural containment, subtle lines, and muted secondary tones that recede into the background, letting content take prominence.
- **Distraction-Free Editorial Space:** Reading modes shift warmth and texture to provide an immersive, offline-first reading room that feels closer to bound paper than ephemeral web pages.
- **Durable Simplicity:** Built to function seamlessly in server-rendered environments with fast, predictable rendering, zero visual jitter, and dependable spatial rhythm.

## Colors

The palette establishes clear visual separation between systemic workspace controls and content consumption canvases:

- **Primary & Interactive Blue Range:** 
  - Brand Anchor (`#152F4A`): Header bars, structural framing, high-contrast focal points.
  - Active Structure (`#24476B`): Selected navigation states, secondary headers, elevated utility frames.
  - Interactive Action (`#315E91`): Actionable elements, standard links, focus rings, and primary action buttons.
- **Accents & States:**
  - Teal Highlight (`#289C91`): Archival metrics, progress indicators, snapshot verification badges, and tag highlights.
  - Status Success (`#16845B`): Snapshot saved, offline synced, verified preservation.
  - Status Warning (`#D18A24`): Rate limits, low storage capacity, partial resource loads.
  - Status Error (`#D64545`): Snapshot timeouts, dead URLs, sync failures.
- **Surfaces & Layout:**
  - Workspace Canvas (`#F5F7F9`): Soft, cool base providing distinct contrast against clean white cards.
  - Reading Sanctuary Surface (`#FAF8F4`): Tinted warm ivory for the dedicated article reading mode, reducing optical fatigue during long sessions.
  - Surface White (`#FFFFFF`): Raised cards, dropdowns, contextual popovers, sidebars.
  - Border System (`#E2E8F0`): Fine, crisp 1px borders defining panels and cards without heavy shadows.
- **Typography & Tonal Contrast:**
  - Slate Charcoal (`#1C2937`): Primary reading text, ensuring WCAG AAA legibility against white and ivory surfaces.
  - Muted Metadata (`#66768A`): Timestamps, domain provenance, reading time estimates, and disabled states.

## Typography

The typography strategy leverages two distinct typefaces to handle utility versus focused reading:

- **UI & Workspace Chrome (Inter):** Applied across dashboards, lists, filters, input fields, badges, and system status indicators. Inter guarantees balanced layout rendering across complex Vietnamese diacritics and compact tabular information.
- **Dedicated Reading Mode (Merriweather):** Applied exclusively within the reader viewport (`reader-title` and `reader-body`). It features a generous 1.75 line-height ratio (`32px` line height on `18px` text) to eliminate eye tracking errors across long-form content.
- **Pacing & Diacritics:** Text rendering uses native font-smoothing and explicit line heights to prevent accent clipping on uppercase Vietnamese vowels (`Ậ`, `Ể`, `Ỗ`, etc.).

## Layout & Spacing

The layout is built on a structured 12-column grid system for workspace views, paired with a specialized single-column constrained container for the offline reader:

- **Dashboard / Library Grid:**
  - Desktop (>1024px): 12-column fluid grid, `margin: 2rem`, `gutter: 1.5rem`. Max width capped at `1440px`.
  - Tablet (768px - 1023px): Collapses navigation to a compact rail or top bar; cards flow into 2 equal columns.
  - Mobile (<768px): Single column, `margin-mobile: 1rem`, `gutter-mobile: 1rem`. Controls collapse into full-width tap targets.
- **Dedicated Editorial Reader View:**
  - Independent of the 12-column dashboard grid.
  - Centered reading column strictly constrained to a maximum width of `780px` (`48.75rem`) to maintain optimal line lengths of 65–75 characters per line.
  - Reader sidebars (table of contents, annotations) sit outside this 780px viewport or collapse into floating overlays on displays narrower than 1280px.

## Elevation & Depth

Visual hierarchy is maintained primarily through surface contrast and crisp 1px borders, avoiding heavy, distraction-causing drop shadows:

- **Base Layer (Level 0):** Canvas backgrounds (`#F5F7F9` for workspace, `#FAF8F4` for reader) without border or elevation.
- **Card & Workspace Surface (Level 1):** Solid white surface (`#FFFFFF`), `border: 1px solid #E2E8F0`, paired with a micro-shadow: `box-shadow: 0 1px 3px rgba(21, 47, 74, 0.05)`.
- **Interactive Hover & Drag (Level 2):** When interacting with library cards: `box-shadow: 0 4px 12px rgba(21, 47, 74, 0.08)`, border subtly transitions to `#CBD5E1`.
- **Overlays & Modals (Level 3):** Flyout drawers, archive history dialogs, and floating snapshot preview toolbars: `box-shadow: 0 12px 32px rgba(21, 47, 74, 0.12)`, border `1px solid #CBD5E1`.

## Shapes

The design system adopts a disciplined, soft radius model (`0.25rem` / `4px` base) for structural interface elements, juxtaposed with smooth pill-shaped elements for classification tags:

- **Cards, Panels & Inputs:** Built with `rounded-sm` (4px) to `rounded-md` (8px). This reinforces the utilitarian feel of an organized digital archive.
- **Badges & Status Chips:** Use fully rounded pill silhouettes (`border-radius: 9999px` or 12–16px) to immediately signal state, categorization, and metadata without competing with card geometry.
- **Buttons:** Subtly rounded at 6px (`0.375rem`), maintaining architectural firmness.

## Components

### 1. Buttons
- **Primary:** Background `#315E91`, text `#FFFFFF`, border-radius 6px, padding `0.5rem 1rem`. Hover: `#24476B`. Active: `#152F4A`. Focus ring: 2px offset with `#289C91`.
- **Secondary / Outline:** Background transparent, border `1px solid #E2E8F0`, text `#1C2937`. Hover: background `#F5F7F9`, border `#CBD5E1`.
- **Ghost:** Background transparent, text `#66768A`. Hover: background `rgba(36, 71, 107, 0.06)`, text `#152F4A`.

### 2. URL Archival Input Bar
- Designed as a prominent omnibar in the authenticated header.
- **Initial State:** 1px border `#E2E8F0`, `#FFFFFF` background, slate placeholder, right-aligned capture button.
- **Processing State:** Subtle pulse animation along the bottom border in `#289C91`, input locked, indicator showing `Archiving snapshot...`.
- **Success State:** Border flashes `#16845B`, displays checked icon, automatically appends card to top of library view.
- **Error State:** Border shifts to `#D64545`, error message banner renders beneath the field detailing capture failure (e.g., paywall, timeout).

### 3. Article Cards
- Clean `#FFFFFF` container with `1px solid #E2E8F0` border and `0.5rem` border-radius.
- Top row: Domain favicon, root domain label (`#66768A`), snapshot timestamp, and cached offline status pill.
- Middle: Title in Inter Semi-bold (`#1C2937`), 2-line excerpt in Inter Regular.
- Bottom row: Reading time estimate badge, snapshot preservation size (e.g., `1.8 MB HTML`), and action trigger for the offline editorial reader.

### 4. Status Badges & Chips
- Compact height (`22px` to `26px`), pill-rounded (`9999px`), `0.25rem 0.625rem` padding.
- **Offline Cached:** Background `#EBF6F4`, text `#16845B`, 1px border `rgba(22, 132, 91, 0.2)`.
- **Domain Tag:** Background `#F1F5F9`, text `#24476B`, 1px border `#E2E8F0`.
- **Unread/Reading:** Background `#FEF6EB`, text `#D18A24`, 1px border `rgba(209, 138, 36, 0.2)`.

### 5. Input Fields & Selects
- Background `#FFFFFF`, border `1px solid #E2E8F0`, text `#1C2937`, padding `0.5rem 0.75rem`.
- Focus: Border `#315E91`, box-shadow `0 0 0 3px rgba(49, 94, 145, 0.15)`.

### 6. Editorial Reading Mode Chrome
- Minimalist fixed header bar with a low profile (`48px` height) over `#FAF8F4`.
- Left: Return to library link with chevron icon.
- Center: Reading progress percentage tracker (`#289C91`).
- Right: Font size toggle (`A-` / `A+`), theme tint adjuster (Default Ivory / Pure White / Midnight), and offline download trigger.