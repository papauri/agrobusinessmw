# AgroBusiness Malawi — Claude Code Guide

## What this project is

A **dual-channel agricultural platform** for Malawian farmers:

1. **Multi-page PHP web app** — a set of `.php` pages sharing partials and a
   large vanilla-JS controller. It is *not* a single-page app; `index.html` is a
   two-line redirect stub to `index.php`.
2. **USSD app** — a feature-phone menu system reachable via a shortcode on
   Airtel/TNM Malawi. The handler is `ussd/` and it **is** in this repository
   (6 PHP files). It talks to MySQL directly, not through `api.php`.

## Stack

| Layer | Tech |
|---|---|
| Pages | PHP 8.3+, no framework. `index.php` plus 12 feature pages, shared `partials/` |
| Frontend | Vanilla JS, no framework. `assets/js/app.js` plus per-page controllers |
| Backend API | `api.php` — single file, action-based routing (`?action=`), MySQLi |
| Registration | `register.php` — standalone, owns its own POST handler. See below |
| USSD handler | `ussd/` — POST from the gateway, replies `CON`/`END` |
| Database | MySQL, database `p601229_AgroBusiness_MW` |
| Hosting | cPanel — `agrobusinessmw.com` → `/home/p601229/public_html/agrobusinessmw/` |
| Languages | English (`en`) and Chichewa (`ci`) — see Bilingual below |

**Theme is Japandi, not green.** `--accent #8B7355` (warm taupe) on `--bg #f5f2eb`
(cream), tokens at the top of `assets/css/style.css`. Green (`#16a34a`) is
`--success` only, plus the HTML email template.

## Registration — read this before touching it

`register.php` + `assets/js/register.js` + `assets/css/register.css` are the
**only** registration implementation. This is deliberate and enforced by
`tests/run.sh`.

The project previously had three competing flows writing to the same table: a
modal in `partials/modals.php` driven by ~320 lines of `app.js`, a set of bolt-on
patch scripts, and `api.php`'s `submit_application`. They validated differently,
so the same person could be stored two different ways. They are gone.

Rules:
- Do **not** recreate a registration modal, and do not move any of this into
  `index.php`.
- Do **not** add a second submit endpoint. Extend `register.php`.
- `register.php` handles all three verbs itself:
  - `GET register.php` renders the form
  - `GET register.php?action=preflight` JSON duplicate check while typing
  - `POST register.php` JSON submit
- It answers the browser **before** sending notification email
  (`register_respond_then_continue`). Mail must never sit on the critical path;
  it used to make registration take 30 seconds when SMTP was slow.

### Phone numbers

The database stores **E.164 only** (`+265888123456`). Two implementations of one
rule set, deliberately kept in lockstep:

- `config/phone.php` — `agro_normalize_phone()`, the authority
- `assets/js/phone-normalizer.js` — `window.AgroPhone.normalize()`, for instant feedback

Accepted: `0888123456`, `0888 123 456`, `888123456`, `265888123456`,
`+265888123456`, `00265888123456`, and any explicit international number
(`+44 7700 900123`).

Rejected rather than guessed: a bare 9-digit number that is not a Malawi mobile
prefix, and `265` + a trunk zero (`2650888123456`) which is a common typo.
**Never add a rule that assumes a country code.** A wrong number means a farmer
who is never contacted.

If you change one file, change the other and run `php tests/phone_test.php` and
`node tests/phone_test.mjs` — the second checks parity against the first.

## Bilingual — English and Chichewa

The reader's language lives in `localStorage.preferredLanguage` (`'en'` / `'ci'`).
`assets/js/i18n.js` owns reading and writing it and broadcasts `agro:langchange`;
`app.js` routes its own switcher through `_persistLanguage()` so the standalone
pages re-render instead of waiting for a reload.

Five translation tables, all with complete key parity:

| Table | Covers |
|---|---|
| `app.js` `this.texts` | dashboard and the shared views |
| `assets/js/register.js` `copy` | the registration page |
| `assets/js/directory-navigation.js` `copy` | Sellers / Buyers |
| `assets/js/market-insights-page.js` `copy` | Market Insights |
| `register.php` `REGISTRATION_STRINGS` | server-side validation + the applicant's email |

Rules:
- **Never hardcode a user-facing string** in a controller. Add a key to that
  file's table. `tests/run.sh` fails on new hardcoded strings in the standalone
  controllers, and `tests/i18n_parity.py` fails on any key missing from either
  language.
- `register.php` cannot read localStorage, so the client sends `lang` with the
  preflight and the POST. Errors come back localised, plus a stable `code` for
  anything that needs to branch on the reason rather than the prose.
- The applicant's confirmation email goes out in the language they registered
  in. The review team's copy is always English.
- **Watch noun-class agreement.** Chichewa concords agree with the noun class of
  the subject, so a sentence built around a variable label ("{label} imeneyi
  yalembetsedwa") is only correct for some labels. Put the variable after a
  fixed subject instead — see `duplicate` in `REGISTRATION_STRINGS`.
- Chichewa strings run longer than English. Re-check 320px after adding any.

## Credentials & environment

Credentials live in `.env` (gitignored). Never hardcode them.

```
DB_HOST=...
DB_NAME=p601229_AgroBusiness_MW
DB_USER=...
DB_PASS=...
DB_PORT=3306
```

`config/database.php` is the single loader (`agro_load_env()`) and connector
(`agro_db_connect()`). Do not write another copy — there were three.

## Running locally

```bash
php -S localhost:8080
```

App → http://localhost:8080 · API health → http://localhost:8080/api.php?action=test

## Testing

```bash
bash tests/run.sh              # lint + phone contract + i18n parity + structural gates
php  tests/phone_test.php      # phone normalisation contract
node tests/phone_test.mjs      # browser/server parity
python3 tests/i18n_parity.py   # en/ci key parity across all five tables

# Browser tests — need a running server and a database
node tests/browser/registration_flow.mjs
node tests/browser/directory_flow.mjs
node tests/browser/navigation_flow.mjs
node tests/browser/language_flow.mjs    # Chichewa end to end
node tests/browser/whatsapp_flow.mjs    # WhatsApp contact wiring
node tests/browser/page_health.mjs      # every page, 320→1280px
node tests/browser/chichewa_overflow.mjs # Chichewa at 320/360/390px
```

`tests/run.sh` includes structural gates that fail if the registration modal,
a duplicate registration endpoint, a second `escapeHtml`, a reference to a
non-existent table, or a committed credential reappears.

## Key files

| File | Purpose |
|---|---|
| `index.php` | Dashboard / home |
| `register.php` | **Registration — form, preflight and submit. Standalone.** |
| `status.php` | Application status lookup (uses the shared status modal) |
| `prices.php`, `weather.php`, `market-insights.php`, `sellers.php`, `buyers.php`, `pest-control.php`, `farming-tips.php`, `farming-guide.php`, `basic-info.php`, `privacy.php` | Feature pages |
| `api.php` | All read APIs plus community price submission |
| `admin/index.php` | Standalone admin panel — session login, CSRF, throttle |
| `config/database.php` | `.env` loading, DB connection, `get_result()`-free fetch helpers |
| `config/phone.php` | Canonical phone normalisation (server) |
| `config/mailer.php` | SMTP + branded HTML email helpers |
| `config/fews.php` | FEWS reference-price helpers |
| `assets/js/app.js` | Main controller — `AgroBusinessRevolution` class |
| `assets/js/register.js` | Registration controller |
| `assets/js/directory-navigation.js` | Sellers/Buyers contact-first directory |
| `assets/js/market-insights-page.js` | Market Insights page |
| `assets/js/phone-normalizer.js` | Canonical phone normalisation (browser) |
| `assets/js/i18n.js` | Shared language state (`AgroLang`) |
| `partials/` | `head`, `nav`, `footer`, `scripts`, `modals`, `content-screen`, `function-page` |
| `p601229_AgroBusiness_MW.sql` | Schema of record — 24 tables, matches production |
| `migrations/` | Additive migrations for existing deployments |
| `.env` | Secrets (gitignored, never commit) |

## API actions (`api.php`)

All return JSON, always HTTP 200 with `{"success": bool, ...}` so the frontend
can read errors. Routing via `?action=`:

| Action | Params | Notes |
|---|---|---|
| `test` | — | DB health check |
| `districts` | — | All districts |
| `crops` | — | Crop registry |
| `crop_prices` | — | Legacy; superseded by `dual_crop_prices` |
| `dual_crop_prices` | `crop_id?` | FEWS reference + community prices |
| `market_insights` | `district_id?` | **Optional** district. Omit for all districts |
| `sellers` | `district_id?`, `crop?` | **Optional** district — contact-first directory |
| `buyers` | `district_id?`, `crop?` | **Optional** district — contact-first directory |
| `pest_control` | `crop_id`, `district_id` | |
| `farming_tips` | `crop_id` | |
| `basic_info` | — | |
| `markets` | `district_id` | Markets within a district |
| `submit_price` | POST | Community price report |
| `check_application` | `ref` | Public status lookup |

Registration is **not** here — it is `register.php`.

## Database — 24 tables

`districts`, `crops`, `crop_prices`, `market_insights`, `sellers`,
`seller_contact_details`, `seller_crops`, `buyers`, `buyer_contact_details`,
`buyer_crops`, `farming_best_practices`, `pest_control_tips`,
`basic_farming_info`, `community_qa`, `ratings`, `crowdsourced_prices`,
`onboarding_applications`, `markets`, `price_overrides`, `admin_users`,
`admin_login_attempts`, `price_markets`, `price_areas`, `price_review_audit`.

`p601229_AgroBusiness_MW.sql` **is** the schema of record. It was regenerated on
2026-08-16 from a production export and verified by restoring it and diffing
`information_schema` against production: 156 columns, 66 indexes, 19 foreign keys
and 24 engines, all identical. `tests/run.sh` fails if a table goes missing from
it or if PHP queries a table it does not create.

**If this file and production ever disagree, production wins** — correct the file,
do not ALTER the live database to match it. For an existing deployment built from
the old file see `migrations/2026-08-16-schema-of-record.sql`.

Things the schema will tell you that the code does not:

- `seller_contact_details` and `buyer_contact_details` each carry a
  `whatsapp_number` column, wired end to end: `admin/index.php` copies it from
  the application on approval, `api.php` returns it on `sellers`/`buyers`, and
  the directory prefers it, falling back to `phone_number` when it is absent.
  Both columns are UNIQUE, so store NULL rather than `''` when there is none.
- `price_markets` / `price_areas` are curated market and area lists with **no
  reader in this repository**. They are `utf8mb4_unicode_ci` while every other
  table is `utf8mb4_0900_ai_ci`; comparing a string column across that boundary
  raises "Illegal mix of collations".
- `price_review_audit` is read by `admin/price-audit.php`.
- `crowdsourced_prices.area_id` exists but is NULL on every production row.
- Contact tables enforce UNIQUE on `phone_number` and on `whatsapp_number`: one
  contact row per number.

`ratings` and `community_qa` are read but never written — seed-only today.

## USSD architecture

- Gateway POSTs to `ussd/` with `sessionId`, `phoneNumber`, `serviceCode`, `text`
- Handler replies `CON <menu>` to continue or `END <message>` to close
- `ussd/` talks to MySQL directly; keep it in sync with schema changes
- Weather uses `ussd/config.php`'s `$district_coords` — **not** `app.js`'s copy

## Weather

**Open-Meteo** (free, no API key), same API for web and USSD. District lat/lon
are embedded in `app.js` `this.districtCoords` and `ussd/config.php`.

## Conventions

- No PHP framework — plain PHP with MySQLi
- No JS framework — vanilla JS
- **Every SQL call is a prepared statement.** No string interpolation into SQL,
  not even a table name
- **`get_result()` is banned** — mysqlnd is not guaranteed on the host. Use
  `stmt_fetch_all()` / `agro_stmt_all()` / `bind_result()`
- All DB-sourced values rendered into HTML go through `escapeHtml()` (defined
  **once**, `app.js` top) or `textContent`. Prefer `textContent` and DOM nodes
- Errors: log the detail, serve a generic message. Never return a file path,
  a query, or a driver message to the browser
- Bilingual strings go in `this.texts.en` and `this.texts.ci` in `app.js`
- CORS is open (`*`) — intentional for USSD gateway compatibility
- CSS: colour via `:root` tokens; interactive controls are ≥44×44px
- One controller per page. Do not monkey-patch `app.openService` from a page
  script — route it in `openService` itself
