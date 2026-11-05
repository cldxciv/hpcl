# web-lite - size-budgeted images for shimlacricketground.com

Rebuilt from the originals with a byte budget per image. WebP, sRGB, no EXIF/GPS. Same colour grade as `../web/`.

## slots/ (drop-in replacements for /storage/seed/*.jpg)

| File | Pixels | KB | Notes |
|---|---|---|---|
| home-hero-desktop.webp | 1920x881 | 119 | Home hero ~2.18:1 (shown 1265x581) |
| home-hero.webp | 704x880 | 60 | Home hero mobile 4:5 |
| ground-hero-aerial.webp | 1920x941 | 109 | Ground hero ~2.04:1 (shown 1265x620) |
| ground-panorama.webp | 864x432 | 69 | Panorama 2:1 (3.4:1 on /ground, 1.7:1 on /fixtures) |
| pitch-report.webp | 1200x675 | 69 | Pitch report 16:9 |
| landmark-master-pitch.webp | 800x533 | 39 | Card 3:2 (shown 363x242) |
| vault-dhauladhar-vista.webp | 800x604 | 34 | Vault card ~1.33:1 (shown 371x280) |
| gallery-morning-dew.webp | 680x510 | 45 | Gallery tile 4:3 |

Total for all 8 slots: ~544 KB (previous set: ~2,863 KB).

## gallery/ - 44 photos, <=90 KB each, long edge <=1280px; gallery/thumb - <=15 KB each (~225-400px)

## Loading tips

- Hero: `<link rel="preload" as="image" href="/storage/seed/home-hero-desktop.webp" fetchpriority="high">` and `fetchpriority="high"` + NO `loading="lazy"` on the hero `<img>`.
- Everything below the fold: `loading="lazy" decoding="async"`.
- Always set `width`/`height` attributes (or CSS aspect-ratio) matching the pixel sizes above to avoid layout shift (CLS).
- Use `<picture>` with `home-hero.webp` (mobile, `media="(max-width: 768px)"`) and `home-hero-desktop.webp`.
- Gallery grid: use `thumb/` in the grid, load the full image only in the lightbox.
- Serve with `Cache-Control: public, max-age=31536000, immutable`; the file names are fixed so rename on change.

## Trade-offs
To hit the budgets, some images got a slight pre-softening (Gaussian 0.4-1.0px) and/or ~15-28% fewer pixels. Heroes are ~1920px wide (shown at 1265px), so they are crisp on standard screens and slightly soft on 2x retina; they sit under the site's green overlay. Nothing was upscaled in this set.

See `../web/` for the larger, higher-quality version.
