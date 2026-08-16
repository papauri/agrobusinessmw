# Tests

Two tiers. The first needs nothing; the second needs a running app.

## Static — no server, no database, no credentials

```bash
bash tests/run.sh
```

Runs `php -l` and `node --check` over every source file, the phone-normalisation
contract, and a set of structural gates that fail if a known-bad pattern comes
back:

| Gate | Guards against |
|---|---|
| no legacy registration modal | the modal returning to `partials/modals.php` / `app.js` |
| no duplicate registration endpoint | `submit_application` / `check_duplicate` reappearing in `api.php` |
| exactly one `escapeHtml` | a second, subtly different escape helper |
| no references to non-existent tables | queries against tables no schema creates |
| schema of record covers every table | a table used by code but absent from the `.sql` dump |
| no committed credential | a real secret in a tracked file |
| every referenced asset exists | a `<script>`/`<link>` pointing at a deleted file |

Each gate has been verified to actually fail when the thing it guards is
reintroduced. A check that cannot fail is not a gate.

## Browser — needs a server and a database

```bash
php -S 127.0.0.1:8080 &          # with a populated .env
node tests/browser/registration_flow.mjs
node tests/browser/directory_flow.mjs
node tests/browser/page_health.mjs
```

`page_health.mjs` loads every page at 320/360/390/430/768/1280px and fails on a
non-200, a console error, a failed same-origin request, or any horizontal
overflow. It also reports interactive elements under 44px tall.

Set `CHROMIUM_PATH` if your Chromium is not at `/opt/pw-browsers/chromium`.

### Standing up a throwaway database

The browser tests write rows, so point them at a scratch database, never
production:

```bash
mysql -u root -e "CREATE DATABASE agro_test CHARACTER SET utf8mb4;"
mysql -u root agro_test < p601229_AgroBusiness_MW.sql
```

On MariaDB, first rewrite the MySQL 8 collation the dump carries:

```bash
sed 's/utf8mb4_0900_ai_ci/utf8mb4_general_ci/g' p601229_AgroBusiness_MW.sql \
  | mysql -u root agro_test
```

`registration_flow.mjs` generates a fresh phone number per run, so it is
repeatable against a database that already holds earlier runs.
