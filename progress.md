# Pro HPCL website: progress and hand-off notes

Written 2026-11-01 so another agent (or person) can pick the work up cold. This repo is **public**:
never put passwords, API keys, FTP details or `.env` contents in it.

## 1. What this project is

The source of **prohpcl.com**, the site of the Pro HPCL (Himachal Premium Cricket League). A
framework-less **PHP 8** app, no Composer or npm.

- `index.php` is the front controller. Routing is in `index.php` (`$dynamicRoutes`, a few `if ($path === ...)` blocks), `routes.json` (static pages in `pages/`) and `includes/admin.php` / `includes/account.php` for their own route tables.
- Logic is in `includes/*.php`; views are `includes/templates/*.html`, partials `includes/partials/`, layouts `includes/layouts/`. The tiny Blade-like engine is `includes/view.php` (`@extends/@section/@include/@yield`, `{!! $var !!}` unescaped, `{{BASE}}` URL prefix).
- CSS is `assets/css/main.css`, JS in `assets/js/`. Admin is under `/admin`.
- Data is MariaDB/MySQL. There is no migration framework: schema changes are `database/*.sql` files plus `bin/*.php` helpers.

### Gotchas that bit us (read before editing)
- `view.php` expands `@directives` **even inside HTML comments**: never write `@include(...)` etc. in a comment.
- Data passed to a view **overrides same-named footer variables** (`email`, `address`, `phones`, `social_links`). Form values are therefore passed as `<field>_value`.
- `query()` in `includes/site.php` always calls `fetchAll()`: use `db()->prepare(...)->execute(...)` for INSERT/UPDATE/DELETE.
- PHP treats non-ASCII characters (such as curly quotes) as part of a variable name: write `"{$who}"` or use `'...'` strings next to variables.
- `$a + $b` keeps `$a`'s keys on conflict (used deliberately for the quick-form defaults).
- Edits to `includes/view.php`-rendered pages: the HTML post-processing in `render()` (`index.php`) makes canonical/og/ld+json URLs absolute and rewrites uploaded images to `/thumb/...`.

## 2. Running it locally

- Database: a Docker MariaDB container named `hpcl-db` (port 3308, volume `hpcl_dbdata`). If pages show a database error, the container has stopped: `docker start hpcl-db` (start Docker Desktop first).
- App: from the repo root, `php -S 127.0.0.1:8080 router.php` with environment variables `APP_ENV=local APP_DEBUG=true DB_HOST=127.0.0.1 DB_PORT=3308 DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... PHP_CLI_SERVER_WORKERS=6`. Ask the owner for the local DB credentials; do not commit them. `APP_ENV=local` is required: it makes mail go to `private/mail.log` and adds `X-Robots-Tag: noindex`.
- Local admin: create one with `php bin/create-admin.php`.
- The local `.env` is a downloaded copy of the live one and is git-ignored. **Never commit it.** Leave it untouched and pass local DB settings through the environment.
- `storage/uploads/*` is git-ignored (about 640 MB on the live site); `storage/cache/` and `private/` are ignored too.

## 3. How production is deployed

- Hosting is Hostinger. **Only FTP (port 21) works from the dev machine**: SSH and remote MySQL are blocked. The FTP login is kept outside the repo (the owner's `ftp_download_temp.py` in the parent folder, not in git).
- Deploy = upload changed files by FTP, verify each file's size afterwards, **back up the live copies first** (download the live code into a dated folder), upload new files first, then DB changes, then modified files, and `index.php` and the layouts **last**.
- DB changes on production: upload a temporary random-name PHP runner, protected by a random token, that `require`s `includes/site.php` and runs the SQL; call it once over HTTPS; delete it straight away. (`bin/*.php` scripts refuse to run outside the command line.)
- **Production writes (files, database, `.env`) need the owner's explicit approval each time.** The harness blocked one such step until the owner answered "go live and deploy. allow changes to production".
- Smoke test after a deploy: main pages return 200, the 404 page works, `/.env` returns 403, `/includes/*` returns 404, the temp runner is gone, chat answers, no console errors.

## 4. What has been done, in order

### 4.1 Audit round 1 (2026-10-31, live site)
End-to-end UI/UX and accessibility audit with Playwright and axe-core: 23 to 28 MB home page, a card-reveal animation that left sections blank for seconds, an autoplaying carousel with no pause, unlabeled filters, low contrast, no `<main>`/`<h1>` on many pages, two `<main>`s on Player Search, inconsistent player pages, placeholder content ("Uncategorized", "City not available"), mobile overflow and small tap targets.

### 4.2 UI/UX and performance sprint (five parallel workstreams)
- **Images**: `includes/thumb.php` serves resized WebP/JPEG at `/thumb/<width>/storage/uploads/...` (GD, disk cache in `storage/cache/thumbs`, ETag, immutable cache headers) and a rewriter in `render()` adds `src`/`srcset`, lazy loading and `fetchpriority`. Home went from about 23 MB to about 1.4 MB. `bin/warm-thumbs.php` can pre-generate (not run on the full set).
- **Global CSS/JS/layout**: skip link, `<main id="main">`, global `:focus-visible` ring, contrast fixes, 44 px tap targets, no text under 12 px, reveal animation delay capped at 250 ms, footer headings and landmarks, Esc closes the mobile menu.
- **Home/join/membership/contact/members**: hidden `<h1>`, carousel interval 6 s with pause button, indicators, hover pause and reduced-motion support, contact buttons, empty states, fixed footer data on the join forms.
- **Teams/videos/blogs**: headings, no "Uncategorized", initials avatars, "Squad to be announced", 44 px chips, related-videos section.
- **Stats/players**: labels on all filters, Player Search and player profile restyled onto the site theme, empty states, `latestStatsSeason()`, sticky player column in mobile tables, rosters from `season_players`.
- Sitemap now includes `/membership`, `/members` and published `/member/<slug>`.

### 4.3 Audit round 2 and fixes
No broken internal links (277 crawled), no horizontal overflow at 360/768/1024/1920 px, axe 0 on public and admin pages, forms/account flows work. Fixed: chat widget is a proper dialog (Esc closes, focus returns, no duplicate banner landmark), mobile menu Esc, "Not added" contrast, big gap on short account pages, next-steps and support email on both thank-you screens, longer page descriptions.

### 4.4 Chat widget (membership sales agent) and production deploy (2026-10-31)
- `includes/agent.php` + `assets/js/agent.js` + `partials/agent-widget.html`: POST `/agent/chat`, answers with Claude from the live tier data, stores conversations in `agent_conversations` / `agent_messages` (`database/agent.sql`). Needs `ANTHROPIC_API_KEY` (and optional `ANTHROPIC_MODEL`) in `.env`.
- Abuse guards: 60 messages per session and **40 per IP per hour** (a visitor can invent session ids, so the IP cap is the real limit).
- `includes/whatsapp.php` (`/whatsapp/webhook`) is deployed but inert: no WhatsApp tokens are set on production.
- **Deployed to prohpcl.com on 2026-10-31**: all of 4.2, 4.3 and 4.4 (39 modified and 5 new files), the two agent tables, and the API key added to the live `.env`. Verified live: 18 pages 200, WebP thumbs served, chat replies, `.env` 403.

### 4.5 Membership popup (2026-11-01, **LIVE since 2026-11-02**)
- `partials/membership-popup.html` + `assets/js/membership-popup.js` (markup from `membershipPopupHtml()` in `includes/membership.php`, wired through a view composer in `includes/content.php`). Opens 5 s after `window.load`; quiet for 7 days after being shown and 30 days after a request; skipped on `/membership*`, `/join-committee`, signup/login/account/admin/shop pages and while the chat panel is open. Esc, backdrop and X close it; focus is trapped and restored.
- Fields: tier, name, email, mobile. POST `/membership/quick` (`handleMembershipQuick()`) saves into `membership_applications` with business name "Not provided" and a note that it came from the popup. No payment. Same validation, honeypot and 6/hour/IP limit as the full form.

### 4.6 Absolute canonical / social URLs (2026-11-01, **LIVE since 2026-11-02**)
Canonical, `og:url`, `og:image`, `twitter:image` and JSON-LD URLs were relative (`/membership`) on every page; `render()` in `index.php` now makes them `https://prohpcl.com/...` (from `SITE_URL`).

### 4.7 Member photo, payment completion and share kit (2026-11-01, **LIVE since 2026-11-02**)
Flow: choose tier, fill the join form (optional photo), pay. When a payment is confirmed (browser callback, Razorpay webhook or the admin "Check payment" button, all through `membershipPaymentReceived()` in `includes/membership-join.php`) the code calls `membershipActivateMember()` (`includes/member-share.php`): it creates a **published** `members` row from the application (name, tier, city, website, contact, photo; the applicant's private message is not copied), a private `share_token`, approves the application and emails the kit (`membershipSendShareKit()`). A team-unpublished profile stays unpublished.
- Photo: `includes/member-photo.php` checks type and size by content, rotates by EXIF, resizes to 1200 px and saves a clean JPEG in `storage/uploads/members/photos`.
- Creative: `includes/member-creative.php` draws a 1080x1080 PNG with GD + FreeType (fonts in `assets/fonts`, Bebas Neue and Barlow, OFL). Served at `/member/<slug>/creative.png` (`?download=1` to save), cached in `storage/cache/creatives`, and used as the profile's `og:image`. **Canva is not connected to Claude sessions**, so the design is an editable background (`assets/creative/bg-square.png`) plus a layout array `memberCreativeLayout()`: see `assets/creative/README.md`. `bin/build-creative-assets.php` rewrites the default background and `sample.png`.
- Share page: `/membership/share/<32-hex token>` (noindex): picture, download, public profile link, caption, copy buttons, and share links for WhatsApp, Facebook, X, LinkedIn, Telegram and Email plus the native share sheet.
- Public profile `/member/<slug>` shows the photo and carries the creative as its social preview.
- Admin (`includes/admin-membership.php`): a Progress column (Complete / Paid · profile hidden / Awaiting payment / Profile live / Applied), a Completed tab, the photo, and a share-kit panel (preview, private link, open, download, email to member, or "Publish profile and create share kit" for requests that did not pay). Route `POST /admin/membership/<id>/sharekit`.
- DB columns (`database/membership-share.sql`; `php bin/migrate-membership-share.php` adds them idempotently): `membership_applications.photo`, `.share_token` (unique), `members.photo`.
- Tested locally end to end with a simulated payment: one profile and one token even if the payment is recorded twice, page/creative/email/admin all correct, axe 0, no overflow.

### 4.8 CricHeroes profile links (2026-11-01, **LIVE since 2026-11-02**, waiting on data)
CricHeroes has Pro HPCL player profiles. Built the plumbing: `database/cricheroes.sql` (`players.cricheroes_url`), `includes/cricheroes.php` (only `https://cricheroes.com|in` links accepted), a "CricHeroes profile" field in `/admin/players`, a "View on CricHeroes" button on `/player/<slug>`, and `bin/import-cricheroes.php links.csv [--apply]` (CSV columns `player,url`; match by slug or exact name; dry run by default). **The real profile URLs have not been provided**: guessed CricHeroes addresses (`/hpclpro` etc.) returned 404, a web search only found the unrelated Hindustan Petroleum HPCL pages. The season-4 registered-players PDF (footer link) has Player IDs `PHPCL/P/<id>` that equal our `players.id`, a usable match key, but no CricHeroes links. 15 players are named "CricHeroes deleted user" and cannot be matched by name.

### 4.9 Season 4 fixtures and groups (2026-11-02, **LIVE**)
The organisers' "PRO HPCL 2026 Fixtures" PDF is published at `/fixtures` (and `/fixtures/season/<n>`), linked from the main nav (FIXTURES), the footer and the Season 4 teams page.
- Data: table `season_fixtures` (`database/fixtures.sql`; one row per match slot: date, slot 1 to 3 as in the PDF's Match 1/2/3 columns, stage, pool, two teams or a knockout label + detail, or a break day). The Season 4 rows come from `includes/fixtures-seed.php` (also `bin/seed-season4-fixtures.php`); it is the one place that holds the PDF's data and the name mapping below. Re-running it replaces Season 4's fixtures.
- Groups: `season_teams.pool` is `A` or `B` for the 12 teams in the schedule. The Season Teams page shows "Group A / Group B" headings when the season has fixtures (`includes/teams.php`).
- PDF name to site team: Cyberbell Tech = "CYBERBELLS TECH TITANS KOTKHAI", Rohru Knight Hawks = "Knight Hawks", Indian Saga = "Indian Saga Royals Shimla", Shilon Bagh Royals = "Shilonbagh Royals", Chudeshwar Legends = "Chudeshwar Legends Sirmaur"; the rest match exactly. The site's own team names are shown (all-capitals names are title-cased); team records were **not** renamed because teams are shared by all seasons.
- Schedule reading: Group A 17 to 21 Nov, 22 Nov pitch preparation, Group B 23 to 27 Nov, 28 Nov pitch preparation, quarter-finals 29 Nov (QF 1 to 3) and 30 Nov (QF 4), SF 1 on 30 Nov, SF 2 and the **Final both on 1 Dec**. The PDF has no times or venues, so none are shown. The Final on 1 Dec is read from the table layout (Match 2 column of the 1 Dec row): worth confirming with the organisers.
- `catalog.php` now counts a squad only while its team is in that season (`season_teams` check in the player page and the player search), so taking a team out of a season leaves no dead team links.
- **Deployed as an isolated release**: only `index.php`, `includes/teams.php`, `includes/catalog.php`, `header.html`, `footer.html` plus the new `includes/fixtures.php` and `includes/templates/fixtures.html` were changed on the server (the same edit script was applied to fresh copies of the live files, so none of the other pending work shipped). Pre-change copies of the five files are in the session backup `live_backup_20261102_fixtures`.
- **Pending approval**: the 8 Season 4 teams that are not in the schedule (Bat Breaker Rampur, Boom Shiva, Commando The Rising Star Solan, Mountain Army by TGW, Sidhpur Warriors, Silent Killer Bilaspur, Valley Victor Jubbal, Wine Association) are still listed on the live Season 4 teams page under "Other teams". `php bin/seed-season4-fixtures.php --remove-others` (or the runner method, `seedSeason4Fixtures(true)`) takes them out of Season 4 while keeping their squads, and prints the SQL that restores them. The production permission check blocked that DB delete until the owner confirms it.

### 4.10 The shop (2026-11-05, **deployed, hidden until switched on**)
Full shop with cart, Razorpay checkout, orders and admin; the code is live but the shop answers 404 until an admin switches it on (Admin > Shop > Settings, or `site_settings.shop_enabled = 1`, or `SHOP_ENABLED=true` in `.env`).
- Storefront: `includes/shop.php` (listing, product pages; only `is_active` products/categories are public), `includes/shop-cart.php` (session cart `shop_cart`, lineKey `productId:variantId`, server-side prices), templates `shop.html`, `shop-product.html`, `shop-cart.html`, assets `shop.css`/`shop.js`; SHOP and CART in the header (`includes/content.php`).
- Checkout: `includes/shop-checkout.php` (`/shop/checkout`, POST `/shop/checkout/place|verify`, `/shop/order/<token>`, `shopPaymentReceived()` called from the Razorpay webhook), `includes/shop-orders.php` (`shopOrderSetStatus()` etc.), `includes/shop-mail.php` (HTML+text emails), account "My orders" (`/account/orders`). Cart survives signing in (`accountSignIn()` keeps `shop_cart`). Local-only fake-payment seam: `site_settings.shop_fake_razorpay` + `appEnv() === 'local'`; test with `bin/test-shop-flow.php` / `.py`.
- Admin: `includes/admin-shop*.php`, `templates/admin/shop-*.html`, permission `shop`, dashboard tiles. Razorpay keys are entered at Admin > Integrations (root only) and shared with membership/committee payments.
- Data: `database/shop.sql`, `bin/migrate-shop.php` (run on production on 2026-11-05 via the temp runner: it hid the two old demo products), tables `shop_orders`, `shop_order_items`, `shop_order_events`. Catalogue tools: `bin/import-shop-products.php` (CSV, `--dry-run`), `bin/build-shop-placeholders.php`, `bin/seed-shop-demo.php` (draft demo products, never run on production).
- To open the shop: add real products (import the owner's list; copy the photos to `storage/uploads/shop/products/` on the server), save Razorpay keys (test first) and the SMTP password, set shipping and the notification email in Shop > Settings, place a test order, then switch the shop on.
- Pre-change copies of the 16 replaced files: session scratchpad `live_backup_20261105_shop`.

## 5. What is live and what is not

Everything in this repo is on prohpcl.com as of 2026-11-02 (commit d717169 plus the Season 4 team removal, which is a data change). The 14 new files, the 17 changed files and the database columns (`membership_applications.photo`, `.share_token`, `members.photo`, `players.cricheroes_url`, `users.team_prompted_at`) were deployed that day; the live copies replaced by the last release are in the session scratchpad (`live_backup_20261102_*`).

| Area | On prohpcl.com |
|---|---|
| UI/UX sprint, thumbnails, a11y, perf, chat widget | yes |
| Membership popup (`/membership/quick`), absolute canonical/og URLs | yes |
| Member photo, share kit, creative (GD + FreeType confirmed on the server), admin changes | yes, but the paid path needs Razorpay and the share-kit mail needs SMTP (below) |
| CricHeroes link field/button/importer | yes, no URLs loaded yet |
| Season 4 fixtures, groups, nav/footer links | yes |
| The 8 non-fixture teams removed from Season 4 (squads kept) | yes (2026-11-02); restore SQL: `INSERT INTO season_teams (season_id, team_id, pool) VALUES (4, <id>, NULL)` for ids 44, 42, 50, 43, 31, 40, 46, 17 |
| Season 4 shows 12 teams (`seasonPlayingTeamSql()` in `includes/site.php`: fixture teams only while a season has fixtures) | yes |
| Homepage/season/team card refinements, player-card overlap fixes, centred owners, membership card text and slider | yes |
| The shop (cart, Razorpay checkout, orders, admin) | code live, shop hidden (404) until switched on; no products or Razorpay keys yet |
| Favourite-team prompt after first login (`/account/team`, `users.team_prompted_at`) and the HTML welcome email (`includes/account-mail.php`) | yes; the email needs SMTP |

## 6. Known gaps and next steps

1. Nothing is waiting to deploy. Always compare the live files with the repo before the next release (size check over FTP, then a normalised diff).
2. **CricHeroes**: get the real profile links (CSV of player/ID and link) and run the importer; or add one tournament-level link if per-player links are not available.
3. **Razorpay is not configured on production**, so the paid path can only be exercised by admin activation until the keys and `membership_payment_enabled` are set (Admin, Settings, Integrations). Test mode first.
4. **SMTP password is empty on production**: the share-kit email will fail (logged) until set; the kit link is still shown in admin and after payment.
5. WhatsApp agent needs its Meta tokens to do anything.
6. Footer link "Registered Players Season 4" is a `footer_links` DB row (a PDF): relabel in admin.
7. A test conversation row `livesmoke123456` exists in the live `agent_conversations`; harmless, can be deleted.
8. Performance on slow 4G: first paint is held by render-blocking Google Fonts, the Bootstrap CDN CSS and `main.css`; on some pages the largest element is the decorative `assets/imgs/element.png`. Self-hosting fonts/Bootstrap and inlining critical CSS would help. `assets/imgs/dots.png` is 134 KB.
9. Smaller ideas: a 1080x1920 story version of the creative; admin upload of a member's photo on `/admin/members/<id>`; long email addresses overflow the facts grid on the admin request page.

## 7. Testing approach used

Python Playwright (Chromium) drove the local site and the live site read-only: crawl links, widths 360 to 1920, Slow-4G throttling, keyboard walk, form submissions, popup timing, share flow. axe-core 4.10.2 for accessibility (inject the script with `evaluate` on admin pages, where the CDN script is blocked). The scripts live in the Claude session scratchpad, which is temporary: recreate them as needed. Lint with `php -l <file>`. Always look at screenshots before calling a visual fix done.

## 8. Working agreements with the owner

- "Go live" type permissions are explicit and per task; ask before any production write.
- Keep changes surgical and match the surrounding style; no new dependencies.
- The separate sponsorship deck work (HTML + PPTX builders) is not in this repo.
- Reports should be plain and honest: say what was tested, what was not, and what is still local only.
