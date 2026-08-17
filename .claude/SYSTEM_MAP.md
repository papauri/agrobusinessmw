# AgroBusiness Malawi — System Map

Regenerated 2026-08-16 against the code as it stands, and verified against a
**live database**: the schema of record was restored into a scratch MySQL
instance, the app was served from it, and every API action and page was
exercised in Chromium. Claims below are from execution, not inference, except
where marked **[static]**.

This replaces the 2026-07-10 map, which described an app that no longer exists
(22 API actions, an admin token, three registration flows).

---

## 1. Feature inventory

| Feature | Page | Controller | API | Tables | Channel |
|---|---|---|---|---|---|
| Home / dashboard | `index.php` | `app.js` | `districts`, `crops` | `districts`, `crops` | web |
| Crop prices | `prices.php` | `app.js`, `price-report-story.js`, `sortable-table.js` | `dual_crop_prices`, `markets`, `submit_price` | `crops`, `crop_prices`, `crowdsourced_prices`, `markets`, `price_overrides` | both |
| Market insights | `market-insights.php` | `market-insights-page.js` | `market_insights` | `market_insights`, `districts` | both |
| Find sellers | `sellers.php` | `directory-navigation.js` | `sellers` | `sellers`, `seller_contact_details`, `seller_crops`, `crops`, `districts` | both |
| Find buyers | `buyers.php` | `directory-navigation.js` | `buyers` | `buyers`, `buyer_contact_details`, `buyer_crops`, `crops`, `districts` | both |
| Registered farmers | `farmers.php` | `directory-navigation.js` | `farmers` | `onboarding_applications`, `districts` | web only |
| Weather | `weather.php` | `app.js` | none — Open-Meteo direct | — | both |
| Pest control | `pest-control.php` | `app.js` | `pest_control` | `pest_control_tips`, `crops`, `districts` | both |
| Farming tips | `farming-tips.php` | `app.js` | `farming_tips` | `farming_best_practices`, `crops` | both |
| Farming guide | `farming-guide.php` | `app.js` | none — client-side content | — | web only |
| Basic info | `basic-info.php` | `app.js` | `basic_info` | `basic_farming_info` | both |
| **Registration** | **`register.php`** | **`register.js`** | **none — `register.php` owns its own POST** | `onboarding_applications`, `districts`, `crops` | both |
| Status lookup | `status.php` | `app.js` (status modal) | `check_application` | `onboarding_applications`, `districts` | both |
| Privacy | `privacy.php` | — | none | — | web only |
| Admin | `admin/index.php` | inline | none — direct SQL | `admin_users`, `admin_login_attempts`, `onboarding_applications`, `crowdsourced_prices`, `price_overrides`, `sellers`, `buyers`, contact tables | web (admin) |
| USSD | `ussd/index.php` → `ussd/logic.php` (+ `ussd/helpers.php` for the directory) | — | none — direct SQL | most of the above | USSD only |
| Community Q&A | *(no web page)* | — | none | `community_qa` — read at `ussd/logic.php:267` only | USSD only |

---

## 2. API action catalogue (`api.php`) — 14 actions, all exercised

Every action below was curled against a live database on 2026-08-16 and returned
the shape recorded. All are public; there is no token-gated action left in
`api.php` (the admin token mechanism was removed in an earlier cycle, and
`admin/index.php` uses a session login instead).

| # | Action | Method | Params | Response | Verified |
|---|---|---|---|---|---|
| 1 | `test` | GET | — | `{success, message, districts_count, environment, timestamp}` | 200, 29 districts |
| 2 | `districts` | GET | — | `{success, data:[{id,name}], count, timestamp}` | 29 rows |
| 3 | `crops` | GET | — | `{success, data:[{id,name}], count, timestamp}` | 9 rows |
| 4 | `crop_prices` | GET | — | `{success, data, count, timestamp}` | legacy, no caller |
| 5 | `dual_crop_prices` | GET | `crop_id?` | `{success, fews, community, fews_count, community_count, fews_source, fews_error, community_error}` | 200, degrades cleanly when FEWS is unreachable |
| 6 | `market_insights` | GET | **`district_id?`** | `{success, data, count, timestamp}` | 24 rows all-districts; 1 row filtered |
| 7 | `sellers` | GET | **`district_id?`**, `crop?` | `{success, data, count, timestamp}` | 20 national, 1 filtered |
| 8 | `buyers` | GET | **`district_id?`**, `crop?` | `{success, data, count, timestamp}` | 15 national, 1 filtered |
| 8b | `farmers` | GET | **`district_id?`**, `crop?` | `{success, data, count, timestamp}` — **no contact columns** | approved farmers only; pending/denied excluded |
| 9 | `pest_control` | GET | `crop_id`, `district_id` | `{success, data, count, timestamp}` | rows returned |
| 10 | `farming_tips` | GET | `crop_id` | `{success, data, count, timestamp}` | rows returned |
| 11 | `basic_info` | GET | — | `{success, data, count, timestamp}` | rows returned |
| 12 | `markets` | GET | `district_id` | `{success, data, timestamp}` | find-or-create verified |
| 13 | `submit_price` | POST | JSON | `{success, status, is_member, message, id}` | insert + member match verified |
| 14 | `check_application` | GET | `ref` | `{success, data:{application_ref,user_type,full_name,status,denial_reason,created_at,reviewed_at,district_name}}` | found / not-found / SQLi-safe |

Unknown action → `{"success":false,"error":"Unknown action. Available actions: …"}`.

**`district_id` is optional on `sellers`, `buyers` and `market_insights`.** That
is the change that makes those pages contact- and information-first. It was
required on all three; the pages had to open a district modal before they could
show anything.

**`farmers` is the one public listing built out of `onboarding_applications`.**
That table holds `phone_number`, `whatsapp_number`, `email` and `national_id`
alongside the columns this action reads, and `privacy.php` §3 promises a public
contact listing only for the buyer and seller directories. So the query selects
no contact column at all and restricts to `status = 'approved'`. Both properties
are gated in `tests/run.sh` against the shipped query text, and asserted against
the live JSON in `tests/browser/directory_flow.mjs` step 14.

### Registration endpoints (`register.php`, not `api.php`)

| Request | Response |
|---|---|
| `GET register.php` | HTML form |
| `GET register.php?action=preflight&phone_number=…` | `{success, matches:[{field,label,ref,status}]}` |
| `POST register.php` (JSON) | `{success, reference, phone_number, whatsapp_number}` or `{success:false, error, field}` |

`field` names the input the server rejected so the client can focus it.

---

## 3. Data model — 24 tables, all in the schema of record

| Table | Created by | Read by | Written by |
|---|---|---|---|
| `districts` | `.sql:230` | `api.php`, `register.php`, `ussd/`, `admin/` | seed only |
| `crops` | `.sql:175` | `api.php`, `register.php`, `ussd/`, `admin/` | seed only |
| `crop_prices` | `.sql:201` | `api.php` (`crop_prices`, FEWS path) | seed only |
| `market_insights` | `.sql:341` | `api.php:market_insights`, `ussd/logic.php` | seed only |
| `sellers` | `.sql:472` | `api.php:sellers`, `ussd/logic.php` | `admin/index.php` promotion |
| `seller_contact_details` | `.sql:511` | `api.php:sellers` | `admin/index.php` promotion |
| `seller_crops` | `.sql:550` | `api.php:sellers` | `admin/index.php` promotion |
| `buyers` | `.sql:55` | `api.php:buyers`, `ussd/logic.php` | `admin/index.php` promotion |
| `buyer_contact_details` | `.sql:89` | `api.php:buyers` | `admin/index.php` promotion |
| `buyer_crops` | `.sql:131` | `api.php:buyers` | `admin/index.php` promotion |
| `pest_control_tips` | `.sql:384` | `api.php`, `ussd/` | seed only |
| `farming_best_practices` | `.sql:276` | `api.php`, `ussd/` | seed only |
| `basic_farming_info` | `.sql:30` | `api.php`, `ussd/` | seed only |
| `community_qa` | `.sql:160` | `ussd/logic.php` only | **no writer** |
| `ratings` | `.sql:434` | `ussd/logic.php` only | **no writer** |
| `crowdsourced_prices` | `.sql` (`IF NOT EXISTS`) | `api.php`, `admin/` | `api.php:submit_price`, `admin/` review |
| `onboarding_applications` | **`.sql` — added 2026-08-16** | `register.php`, `api.php:check_application`, `admin/`, `api.php:submit_price` (member match) | **`register.php` only**, plus `admin/` status updates |
| `markets` | **`.sql` — added 2026-08-16** | `api.php:markets` | `api.php:submit_price` (`INSERT IGNORE`) |
| `price_overrides` | **`.sql` — added 2026-08-16**, also lazily in `admin/index.php:178` | `api.php` `cp_apply_overrides` | `admin/index.php` |
| `admin_users` | **`.sql` — added 2026-08-16**, also lazily in `api.php` and `admin/index.php` | `admin/index.php` login | seeded once from `.env` |
| `admin_login_attempts` | **`.sql` — added 2026-08-16**, also lazily in `admin/index.php` | login throttle | login handler |

### Corrected 2026-08-16 against a production export

An earlier pass of this map claimed `price_markets` and `price_areas` did not
exist anywhere and called them invented. **That was wrong.** Both exist in
production with real data (120 and 216 rows), as does `price_review_audit`,
which `admin/price-audit.php` reads. They were missing from the *schema file*,
not from the database, and that file has now been regenerated from production.

| Table | Status |
|---|---|
| `price_markets` | production has it, 120 rows; **no reader by decision** (2026-08-17) — see below |
| `price_areas` | production has it, 216 rows; same |
| `price_review_audit` | production has it, 332 rows; **read by `admin/price-audit.php`** |

Also recovered into the schema file: `whatsapp_number` on both contact tables
(**now wired end to end** — see below), and `area_id` / `verified` /
`reviewed_by` / `reviewed_at` on `crowdsourced_prices`.

**`price_markets` / `price_areas` are intentionally unread.** The user decided on
2026-08-17 to keep the price-location feature retired: its endpoint was a second
price-submission path that bypassed `submit_price`'s member matching and outlier
gate, and its client was a MutationObserver bolt-on that hijacked the form. The
tables and their data stay, so the decision is reversible; if rebuilt, build it
onto `api.php`'s `submit_price` rather than a new endpoint.

**WhatsApp contact chain**: `onboarding_applications.whatsapp_number` →
`admin/index.php` promotion → `seller_contact_details` / `buyer_contact_details`
→ `api.php` `sellers`/`buyers` → `directory-navigation.js`, which prefers it and
falls back to `phone_number`. Both contact columns are UNIQUE; store NULL, not
`''`, when there is no number.

**The schema of record is now complete.** Verified by restoring
`p601229_AgroBusiness_MW.sql` into an empty database and diffing
`information_schema` against a production restore — 156 columns, 66 indexes,
19 foreign keys, 24 engines, all identical — and the app runs
against it — registration, price submission and every read path.

Before this change the dump produced 16 tables, and registration, community
prices and the admin panel all failed on a fresh deployment.

`markets` carries `UNIQUE (district_id, name)`. This is load-bearing:
`api.php:submit_price` does `INSERT IGNORE INTO markets` then selects the row
back. Without the unique key every price report creates a duplicate market.

---

## 3a. Language

`localStorage.preferredLanguage` (`'en'` / `'ci'`) is the single source of truth,
written by `app.js`'s switcher and by `assets/js/i18n.js`'s `AgroLang.set()`.
`i18n.js` broadcasts `agro:langchange`; the standalone controllers listen and
re-render in place.

| Table | en | ci |
|---|---|---|
| `app.js` `this.texts` | 44 | 44 |
| `assets/js/register.js` `copy` | 78 | 78 |
| `assets/js/directory-navigation.js` `copy` | 41 | 41 |
| `assets/js/market-insights-page.js` `copy` | 19 | 19 |
| `register.php` `REGISTRATION_STRINGS` | 32 | 32 |

Parity is gated by `tests/i18n_parity.py`, which is run by `tests/run.sh`.

`register.php` receives `lang` on the preflight and the POST, returns localised
messages plus a stable `code`, and emails the applicant in their own language.

Not translated: `admin/index.php` (review team works in English), `privacy.php`,
and the price-story strings in `app.js`.

---

## 4. Script load order (`partials/scripts.php`)

Order is deliberate:

1. `config.js`, `i18n.js`, `phone-normalizer.js` — plain scripts, define globals
   (`AGRO_CONFIG`, `AgroLang`, `AgroPhone`); `phone-normalizer.js` reads
   `AgroLang` for its validation message
2. `app.js` (deferred) — defines `window.app`
3. `directory-navigation.js`, `market-insights-page.js` (deferred, run after
   `app.js` in document order) — each owns exactly one page and returns
   immediately elsewhere
4. `sortable-table.js`, `price-report-story.js`, `quiet-db-notification.js`

`register.php` does **not** load `app.js`. It loads `i18n.js`,
`phone-normalizer.js` and `register.js` only, and carries its own language
switcher because it does not include the shared content header.

**No page script monkey-patches `app.openService` any more.** Three hook scripts
used to wrap it, so the destination of a dashboard tile depended on load order.
`openService` now routes to standalone pages itself.

---

## 5. Removed in this pass, and why

| Removed | Reason |
|---|---|
| `registration-check.php`, `registration-contact.php` | Bolt-on endpoints for the old modal. `registration-contact.php` was an unauthenticated `UPDATE` on applicant phone numbers |
| `assets/js/registration-contact-validation.js` | Patched the modal at runtime with `stopImmediatePropagation` |
| `assets/js/registration-home-hook.js`, `assets/js/directory-home-hook.js` | Monkey-patched `openService`; folded into `openService` |
| `api.php` `submit_application`, `check_duplicate` | Second registration path with weaker validation — accepted un-normalised phones, ignored WhatsApp |
| Registration modal in `partials/modals.php` | Duplicate element IDs with `register.php` |
| ~320 lines of registration code in `app.js` | Same |
| `price-locations.php`, `price-submit.php`, `price-location-selector.js/.css` | Queried `price_markets` and `price_areas` — tables the schema *file* omitted, so the endpoint returned HTTP 500 on any database built from it. The tables are real (see §above); the feature stays retired by decision, not because they were missing |
| `directory-api.php` | Duplicated `api.php`'s sellers/buyers query with its own DB bootstrap |
| `app.js` `loadSellers`, `loadBuyers`, `loadMarketInsights` | District-first duplicates of the standalone pages |
| `app.js` `showCropDetails`, `getCropFarmingTips`, `getCropMarkets` | Zero call sites repo-wide (build plan objective 3.4) |

---

## 6. Known gaps

- **`ratings` and `community_qa` have no writer.** A farmer can read a rating
  over USSD but never leave one, and Q&A is USSD-read-only. Build plan 2.1.
- **`crop_prices` action has no caller.** Superseded by `dual_crop_prices`. Left
  in place as a public read-only endpoint; harmless.
- **De-promotion on deny is still missing** (`admin/index.php`). Denying a
  previously approved seller/buyer leaves them in the directory, and
  approve→deny→approve promotes twice. Build plan 2.7 — unchanged this pass.
- **Legacy contact numbers.** Rows written before canonicalisation may hold local
  formats. Nothing rewrites them: a bulk UPDATE that guesses a country code is
  exactly the mistake the app now refuses to make. Every read path tolerates both.
- **USSD is now exercised locally, not against a gateway.** `ussd/index.php` is
  driven by POSTing the gateway's own field set (`sessionId`, `phoneNumber`,
  `serviceCode`, `text`) to a local server, in both languages. What that cannot
  prove is how a real operator handles a 182-byte page or a session timeout, so
  a live shortcode test is still owed. **[locally verified, gateway DEFERRED]**
- **A USSD result page has no pagination.** `ussd_fit_lines()` shows what fits
  and appends "+N more", but there is no way to reach the remainder: with the
  back menu spending 51–62 of the 182 bytes, that is about two listings per
  district. The `9. Next` machinery in `parse_navigation()` already paginates
  *district* menus and is the obvious place to extend, but it replays a
  navigation stack and is not a small change. Filed, not done.

---

## 7. What was verified, and how

- **Live database.** MariaDB 10.11, schema restored from
  `p601229_AgroBusiness_MW.sql`, app served by `php -S`.
- **API.** All 14 actions curled: valid params, missing params, bogus IDs,
  SQL-injection strings. No action returned `success:false` on a valid request.
- **Registration.** 15 server-side negative cases (duplicates by phone,
  WhatsApp-vs-phone collision, email, national ID; bad phone, bad WhatsApp, bad
  email, missing name, unknown district, unknown crop, no crops, seller without a
  business name, malformed JSON, SQLi, XSS payload) plus the full 12-step browser
  flow.
- **Phone normalisation.** 41 PHP cases, 44 JS cases, plus a 39-input corpus run
  through both implementations and diffed for parity.
- **Pages.** 13 pages × 6 viewport widths (320/360/390/430/768/1280) in Chromium:
  78 checks, zero horizontal overflow, zero console errors, zero failed
  same-origin requests.
- **Directory + insights.** 13-step browser flow: contact-first render, search,
  district filter, URL state, back button, call/WhatsApp/email/share, Escape,
  deep link, and a check that Market Insights makes **one** request rather than 28.

Not verified: USSD (no gateway), outbound email (no reachable SMTP), the admin
panel's approve/deny path (needs an admin session), and production itself.
