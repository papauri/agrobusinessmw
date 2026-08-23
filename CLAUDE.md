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
| Registration | `config/registration.php` holds the rules; `register.php` (web) and `ussd/registration.php` (USSD) are the channels. See below |
| USSD handler | `ussd/` — POST from the gateway, replies `CON`/`END` |
| Database | MySQL, database `p601229_AgroBusiness_MW` |
| Hosting | cPanel — `agrobusinessmw.com` → `/home/p601229/public_html/agrobusinessmw/` |
| Languages | English (`en`) and Chichewa (`ci`) — see Bilingual below |

**Theme is Japandi, not green.** `--accent #8B7355` (warm taupe) on `--bg #f5f2eb`
(cream), tokens at the top of `assets/css/style.css`. Green (`#16a34a`) is
`--success` only, plus the HTML email template.

## Registration — read this before touching it

**The rules live in `config/registration.php`. The channels are presentation.**

| File | Owns |
|---|---|
| `config/registration.php` | What a valid application is, what counts as a duplicate, the reference format, and the **single** INSERT (`register_store()`). Both channels call it. |
| `register.php` + `assets/js/register.js` + `assets/css/register.css` | The **web** form, preflight, JSON contract and notification email |
| `ussd/registration.php` | The **USSD** step machine — which question comes next, nothing more |

Add a channel by calling `register_store()` with a new `channel` value.
There must be exactly one `INSERT INTO onboarding_applications` in the
repository. This used to be enforced by a test; it is now a convention only.

The project previously had three competing flows writing to the same table: a
modal in `partials/modals.php` driven by ~320 lines of `app.js`, a set of bolt-on
patch scripts, and `api.php`'s `submit_application`. They validated differently,
so the same person could be stored two different ways. They are gone.

Rules:
- Do **not** recreate a registration modal, and do not move any of this into
  `index.php`.
- Do **not** add a second submit endpoint or a second INSERT. Extend
  `config/registration.php` and call `register_store()`.
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

If you change one file, change the other. The paired tests that checked them
for parity are gone, so the two implementations have to be diffed by hand.

## Bilingual — English and Chichewa

The reader's language lives in `localStorage.preferredLanguage` (`'en'` / `'ci'`).
`assets/js/i18n.js` owns reading and writing it and broadcasts `agro:langchange`;
`app.js` routes its own switcher through `_persistLanguage()` so the standalone
pages re-render instead of waiting for a reload.

Six translation tables, all with complete key parity:

| Table | Covers |
|---|---|
| `app.js` `this.texts` | dashboard and the shared views |
| `assets/js/register.js` `copy` | the registration page |
| `assets/js/directory-navigation.js` `copy` | Sellers / Buyers / Farmers |
| `assets/js/market-insights-page.js` `copy` | Market Insights |
| `config/registration.php` `REGISTRATION_STRINGS` | server-side validation + the applicant's email, both channels |
| `ussd/menus.php` `$menu_texts` | every USSD page |

Rules:
- **Never hardcode a user-facing string** in a controller. Add a key to that
  file's table, in **both** `en` and `ci` — nothing checks this automatically
  any more, so a missing key now ships silently.
- `config/registration.php` cannot read localStorage, so the web client sends
  `lang` with the preflight and the POST, and the USSD handler passes the
  language it already has. Both go through `register_lang()`. Errors come back localised, plus a stable `code` for
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

**The test suite was deleted on 2026-08-23 at the maintainer's request.**
`tests/` and `migrations/` no longer exist. There is no `run.sh`, no lint pass,
no phone/i18n parity check and no browser flow.

What that removed, so it is not rediscovered the hard way — these were gates
against bugs that had already happened once:

- a second `INSERT INTO onboarding_applications` (three competing registration
  flows storing the same person differently)
- a re-created registration modal
- a committed credential, and a tracked `.env`
- `p601229_AgroBusiness_MW.sql` drifting from the tables the code queries
- a USSD `CON` page over the 182-character limit (silent mid-word truncation)
- `config/phone.php` and `phone-normalizer.js` disagreeing
- an `en`/`ci` key missing from either side of a translation table
- the `farmers` query selecting a contact column, or listing unapproved rows

Until something replaces it, each of those has to be checked by reading the
code. Lint at minimum before shipping:

```bash
for f in *.php admin/*.php config/*.php ussd/*.php; do php -l "$f"; done
for f in assets/js/*.js sw.js; do node --check "$f"; done
```

## Key files

| File | Purpose |
|---|---|
| `index.php` | Dashboard / home |
| `register.php` | **Registration — form, preflight and submit. Standalone.** |
| `status.php` | Application status lookup (uses the shared status modal) |
| `prices.php`, `weather.php`, `market-insights.php`, `sellers.php`, `buyers.php`, `farmers.php`, `pest-control.php`, `farming-tips.php`, `farming-guide.php`, `basic-info.php`, `privacy.php` | Feature pages |
| `api.php` | All read APIs plus community price submission |
| `admin/index.php` | Standalone admin panel — session login, CSRF, throttle |
| `admin/admarc-prices.php` | ADMARC official price editor — hand entry, source required |
| `config/database.php` | `.env` loading, DB connection, `get_result()`-free fetch helpers |
| `config/phone.php` | Canonical phone normalisation (server) |
| `config/mailer.php` | SMTP + branded HTML email helpers |
| `config/fews.php` | FEWS reference-price helpers |
| `assets/js/app.js` | Main controller — `AgroBusinessRevolution` class |
| `assets/js/register.js` | Registration controller |
| `assets/js/directory-navigation.js` | Sellers / Buyers / Farmers directories |
| `assets/js/market-insights-page.js` | Market Insights page |
| `assets/js/phone-normalizer.js` | Canonical phone normalisation (browser) |
| `assets/js/i18n.js` | Shared language state (`AgroLang`) |
| `partials/` | `head`, `nav`, `footer`, `scripts`, `modals`, `content-screen`, `function-page` |
| `p601229_AgroBusiness_MW.sql` | Schema of record — 24 tables, matches production |
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
| `dual_crop_prices` | `crop_id?` | FEWS reference + community prices + ADMARC official |
| `market_insights` | `district_id?` | **Optional** district. Omit for all districts |
| `sellers` | `district_id?`, `crop?` | **Optional** district — contact-first directory |
| `buyers` | `district_id?`, `crop?` | **Optional** district — contact-first directory |
| `farmers` | `district_id?`, `crop?` | Approved farmer registrations. **Selects no contact column** — see below |
| `pest_control` | `crop_id`, `district_id` | |
| `farming_tips` | `crop_id` | |
| `basic_info` | — | |
| `markets` | `district_id` | Markets within a district |
| `submit_price` | POST | Community price report |
| `check_application` | `ref` | Public status lookup |

Registration is **not** here — it is `register.php`.

### The `farmers` action — read this before changing it

`farmers` is the only public listing built straight out of
`onboarding_applications`, and that table holds `phone_number`,
`whatsapp_number`, `email` and `national_id` in the columns either side of the
ones it reads. Farmers have no directory table of their own — `admin/index.php`
promotes sellers and buyers only — so there is nowhere safer to read from.

Two rules, previously gated against the shipped query text and now convention:

- **Select no contact column.** Not "filter it out later" — never fetch it. A
  `SELECT oa.*` here publishes every farmer's phone number.
- **`status = 'approved'` only.** An unreviewed application is not a vetted
  listing, exactly as for sellers and buyers.

`privacy.php` §3 states what the farmer roster publishes (name, district,
village, crops) and what it never does. Change one and change the other.

## Admin flows — registration, approval, price review

Three things were wrong here until 2026-08-23, all of the same shape: a control
that looked present but could not fire.

- **The duplicate check had no backstop.** `register_store()` catches errno 1062
  as "the UNIQUE key catching a race", but `onboarding_applications` carried
  UNIQUE only on `id` and `application_ref` — so two submissions of the same
  number both passed the SELECT and both inserted. The four `uniq_onboarding_*`
  keys now exist and enforce exactly what `register_find_duplicates()` already
  applied. They are **per column**, so the one case still unguarded is a phone
  equal to a *different* application's whatsapp number.
- **`execute()` does not return false on a duplicate.** PHP 8.1+ makes mysqli
  throw, so testing the return value left the 1062 branch unreachable a second
  way and the applicant saw the generic "try again later" instead of their
  existing reference. `register_store()` now wraps it. `ussd/config.php` hit the
  same trap for connections — assume any `execute()` can throw.
- **Price review recorded `reviewed_by='admin'`.** The identity-aware endpoint
  `admin/price-review.php` existed, but the only thing routing to it was a
  *client-side* intercept in `sortable-table.js`; the plain form posted to the
  inline handler in `admin/index.php`, which hardcoded the string, had no
  status guard, no transaction and no `affected_rows` check. Production already
  carries a row stamped that way. The inline handler is now the equal of the
  endpoint. **A browser intercept is not an access control** — whatever the
  no-JS path does is what the system does.

Also fixed: `admin/index.php` authenticated against `SELECT ... LIMIT 1` rather
than the submitted username (a second admin account could never log in), and its
session omitted `admin_user_id`, which `price-review.php` and
`admarc-prices.php` both require — so a session created there could approve
applicants but not review prices. Both login paths now produce the same session.

Rules:

- **A rejected write must say so.** A stale CSRF token used to fall through both
  the approval and the price-review handlers in silence, re-rendering the page
  with the applicant still pending and no reason given.
- **Never put `$e->getMessage()` in front of a user.** Under PHP 8 that is a
  driver string carrying the query and column names. Log it, show one sentence.
- Reviewing an already-reviewed price report is refused, not applied. It would
  rewrite `reviewed_by`/`reviewed_at` and fire `trg_price_audit_after_update`
  again, adding an audit row for a decision nobody made twice.

## Crops in the directory

Three things carry the "what do they deal in" answer, and they have to agree:

- `admin/index.php` `admin_link_applicant_crops()` writes `seller_crops` /
  `buyer_crops` on approval, matching `crops_of_interest` back to `crops.name`.
  Nothing wrote those tables before 2026-08-17, which is why every approved
  contact showed no crops.

## Approval, denial, and the directory

The directory follows the decision in **both** directions, and the link between
an application and its directory row is the **contact's phone number**:

- `admin_promote_applicant()` on approve; `admin_demote_applicant()` on deny.
  Both are driven from the status *transition*, read under `FOR UPDATE` inside
  one transaction.
- `admin_find_directory_row()` matches `onboarding_applications.phone_number`
  against `seller_contact_details.phone_number`. That is exact, not fuzzy:
  promotion copies the number verbatim, the column is NOT NULL on an
  application, and it carries a UNIQUE key on the contact table. **Never widen
  this to the name** — namesakes are real, and nothing tests for it now.
- Denial deletes the directory row **before** the contact row
  (`fk_sellers_contact` is `ON DELETE RESTRICT`). Crop links go by cascade.
- Freeing the number on denial is also what makes **re-approval** possible. It
  was not: the UNIQUE key rejected the second contact insert, so a second
  approval threw and rolled back, and an applicant once denied could never be
  approved again.
- If the phone on the application is edited after approval, the match fails and
  the admin panel says so explicitly rather than implying a removal happened.
- `api.php` returns `crops` (an array) alongside `crops_display` (a string).
  **Filter on the array, never by substring of the string** — `Beans` would
  otherwise select every `Soybeans` grower. The GROUP_CONCAT separator is a
  newline, not `", "`, so a crop name containing a comma cannot break the split.
- `assets/js/directory-navigation.js` builds the crop dropdown from the crops
  the loaded rows actually name, and always renders a crops strip on a card —
  including a muted "no crops listed", so an absent strip never has to be
  interpreted.

## Database — 25 tables

`admarc_prices`,
`districts`, `crops`, `crop_prices`, `market_insights`, `sellers`,
`seller_contact_details`, `seller_crops`, `buyers`, `buyer_contact_details`,
`buyer_crops`, `farming_best_practices`, `pest_control_tips`,
`basic_farming_info`, `community_qa`, `ratings`, `crowdsourced_prices`,
`onboarding_applications`, `markets`, `price_overrides`, `admin_users`,
`admin_login_attempts`, `price_markets`, `price_areas`, `price_review_audit`.

`p601229_AgroBusiness_MW.sql` **is** the schema of record. It was regenerated on
2026-08-16 from a production export and verified by restoring it and diffing
`information_schema` against production: 156 columns, 66 indexes, 19 foreign keys
and 24 engines, all identical (plus `admarc_prices`, added 2026-08-23). Keep it
in step by hand: every table PHP queries must appear here.

**If this file and production ever disagree, production wins** — correct the file,
do not ALTER the live database to match it. The migration that reconciled an
older deployment was deleted with `migrations/`; recover it from git history
(`git log --diff-filter=D -- migrations/`) if a legacy copy ever needs it.

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
- `admarc_prices` was added 2026-08-23 and is **admin-maintained, never
  fetched** — see ADMARC below. `district_id` 0 means national, the same
  convention `price_overrides` uses, and like that table it carries no FK to
  `districts`.
- `price_review_audit` is read by `admin/price-audit.php` and **written only by
  two triggers** on `crowdsourced_prices` (`trg_price_audit_after_insert` /
  `..._after_update`). No PHP inserts into it. A database restored without those
  triggers keeps the table and the page, records nothing, and raises no error.
- **7 CHECK constraints** enforce E.164 on the contact/application phone columns
  and positivity on the crowdsourced price columns. Both they and the triggers
  were missing from the schema of record until 2026-08-23 — the 2026-08-16
  reconciliation compared columns, indexes, foreign keys and engines, and never
  looked at constraints or triggers. If you re-verify the schema, compare those
  too.
- `onboarding_applications` constrains `whatsapp_number` but **not**
  `phone_number`, while the contact tables constrain both. Promotion copies the
  application's phone into a CHECKed column, so an unnormalised number stored
  there fails at approval rather than at registration. Both channels run
  `agro_normalize_phone()` first, so this is latent — do not add a caller that
  skips it.
- `crowdsourced_prices.area_id` exists but is NULL on every production row.
- Contact tables enforce UNIQUE on `phone_number` and on `whatsapp_number`: one
  contact row per number.

`ratings` and `community_qa` are read but never written — seed-only today.

## USSD architecture

- Gateway POSTs to `ussd/` with `sessionId`, `phoneNumber`, `serviceCode`, `text`
- Handler replies `CON <menu>` to continue or `END <message>` to close
- `ussd/` talks to MySQL directly; keep it in sync with schema changes
- Weather uses `ussd/config.php`'s `$district_coords` — **not** `app.js`'s copy
- **Language lives in a session file, not in `text`.** `$stack[0]` is always
  overwritten from the persisted preference, so sending `text=2*…` does not
  select Chichewa — `00` toggles it, and the toggle must be in the same
  `sessionId`. Worth knowing before you conclude the translation is broken.

### A CON page is 182 CHARACTERS

Not bytes — one emoji is four bytes and one character, and these menus are full
of them. Africa's Talking truncates anything longer, mid-word and without
warning, which on a directory page means half a phone number. `"CON "` and the
trailing back menu are spent before a single line of content, and the Chichewa
back menu is 11 characters longer than the English one.

A test used to walk `$menu_texts` and fail on any page over the limit. It had
to: the main menu was **234 characters (271 in Chichewa)** until 2026-08-17, so
every page had been over the ceiling since the menu was written. Either the
operator is more generous than the documentation or those pages have been
truncating in production — nobody can tell without a live shortcode, so the gate
holds the documented number.

Anything that renders a variable number of rows must go through
`ussd_page_budget()` and `ussd_fit_lines()` in `ussd/helpers.php`: the budget is
derived from the actual suffix, whole lines are kept or dropped (never cut), and
the dropped count is shown. Check both languages by hand after any change.

### Registration over USSD

`10` on the main menu. `ussd/registration.php` asks role → name → district →
village → crops → business name (sellers and buyers only) → confirm, then calls
`register_store()`. The caller's phone comes from the gateway's `phoneNumber`;
they never type it.

Two things that look odd until you know why:

- **It does not use `parse_navigation()` or `$stack`.** That parser reads `0` as
  Back and `9` as Next Page, and crop 9 is Beans — a caller picking Beans would
  have the answer eaten as pagination. Registration keeps its own state in the
  session file and consumes exactly one token per request, the last one.
- **`seen` counts tokens already processed.** A gateway re-delivering the same
  accumulated text must redraw the page, not advance the flow.

### The directory over USSD

`ussd_directory_lines()` is the single query behind Find Sellers and Find Buyers.
It must stay in step with `api.php`'s `sellers`/`buyers`:

- **LEFT JOIN the contact table**, matching `api.php`. Defensive rather than
  load-bearing — `sellers.contact_id` is `int NOT NULL` with an
  `ON DELETE RESTRICT` FK, so a listing with no contact row cannot exist.
- **`phone_number` is nullable and NULL on every production row.** Render the
  `directory.no_number` string, never an empty gap after the colon.
- **The rating is a scalar subquery, not a joined `AVG`.** `ratings` and
  `seller_crops` in one row set fan out against each other; the next aggregate
  added over that product would be wrong.
- Crops split on a newline, same as `api.php`, for the same reason.

## ADMARC prices

**ADMARC's site is `admarc.co.mw`.** `admarc.mw`, `www.admarc.mw` and
`admarc.com.mw` do not resolve at all — which is why the scrape that commit
`9de275c` built went permanently cache-only and the feature was eventually
dropped with the table on 2026-07-10. It was pointed at a host that never
existed. `admarc.co.mw` is live and publishes announcements.

Even so, **prices are entered by hand, not scraped.** ADMARC publishes prose
announcements, not a price feed, and the figures that matter come from more than
one authority — ADMARC for its own buying price, the Ministry of Agriculture for
the annual minimum farm gate list, the Cotton Council for cotton. There is
nothing to poll, and a scraper over prose would silently drift.

The table was populated on 2026-08-23 with the prices then in force (8 crops).
Crops sold at auction — tobacco, tea, coffee — have no gazetted floor price and
deliberately carry no row, as do sweet potato, tomatoes and Irish potatoes.

It came back on 2026-08-23 as **admin-maintained reference data**:

| Piece | Owns |
|---|---|
| `admarc_prices` | The figures. `district_id` 0 = national; a district row wins for its district |
| `admin/admarc-prices.php` | Hand entry. `source_note` is **required** — an unattributed official price is not published |
| `api.php` `admarc_effective_prices()` | Resolution: newest `effective_from` that is not in the future, per crop+district |
| `app.js` `loadCropPrices()` | The "ADMARC Official" column, resolved onto existing rows |
| — | The FEWS column is labelled **Global Benchmark** to readers; the price table carries no Source column |

Rules:

- **A price change is a new row, not an edit.** `(crop_id, district_id,
  effective_from)` is UNIQUE and the API serves the newest row that has taken
  effect. That is what lets anyone check what the official floor was on the day
  a farmer sold; editing in place would rewrite that silently. The admin page
  only inserts and deletes for this reason.
- **Never invent a figure.** These are government floor prices farmers use to
  judge whether a trader's offer is fair, so a wrong one costs a farmer money. An
  empty table renders a dash, never a placeholder or an interpolated guess.
- ADMARC does **not** get rows of its own in the price table — a national price
  covers every district, so emitting it as a row would duplicate every crop. It
  is resolved onto the records FEWS and community reports already produced.
- A future `effective_from` is staged, not shown, so next season's price can be
  entered the day it is announced.

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
