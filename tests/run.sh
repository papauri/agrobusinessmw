#!/usr/bin/env bash
#
# AgroBusiness Malawi — the whole test suite. Run from the repository root:
#
#   bash tests/run.sh
#
# Everything here is static or self-contained: no database, no network, no
# credentials. The checks that genuinely need a live database live in
# tests/api_smoke.sh, which is separate because it needs a server to talk to.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

pass=0
fail=0

report() {
    if [ "$1" -eq 0 ]; then
        printf '  ok    %s\n' "$2"; pass=$((pass + 1))
    else
        printf '  FAIL  %s\n' "$2"; fail=$((fail + 1))
    fi
}

echo "== PHP syntax =="
while IFS= read -r file; do
    out=$(php -l "$file" 2>&1)
    status=$?
    report $status "php -l $file"
    [ $status -ne 0 ] && echo "$out"
done < <(find . -name '*.php' -not -path './.git/*' | sort)

echo "== JavaScript syntax =="
while IFS= read -r file; do
    out=$(node --check "$file" 2>&1)
    status=$?
    report $status "node --check $file"
    [ $status -ne 0 ] && echo "$out"
done < <(find . -name '*.js' -not -path './.git/*' -not -path './node_modules/*' | sort)

echo "== Phone normalisation =="
php tests/phone_test.php;  report $? "config/phone.php contract"
node tests/phone_test.mjs; report $? "assets/js/phone-normalizer.js parity"

echo "== Bilingual parity =="
python3 tests/i18n_parity.py; report $? "en/ci key parity across all translation tables"

echo "== Structure =="

# Registration must have exactly one implementation. These greps are the guard
# against the modal creeping back in; three competing flows is what they cost us.
count=$(grep -rl "register-modal\|openRegistrationModal\|reg-step-content" \
    --include='*.php' --include='*.js' . 2>/dev/null | grep -v '^./.claude/' | wc -l)
[ "$count" -eq 0 ]; report $? "no legacy registration modal (found $count file(s))"

count=$(grep -rn "action=submit_application\|action=check_duplicate" \
    --include='*.php' --include='*.js' . 2>/dev/null | grep -v '^./.claude/' | grep -cv 'deliberately')
[ "$count" -eq 0 ]; report $? "no duplicate registration endpoint (found $count reference(s))"

# Exactly one HTML-escape helper in app.js; a second one always drifts.
count=$(grep -c "function escapeHtml" assets/js/app.js)
[ "$count" -eq 1 ]; report $? "exactly one escapeHtml helper in app.js (found $count)"

# Tables that no schema creates. price_markets/price_areas were invented by an
# earlier change and every query against them threw a fatal.
count=$(grep -rn "price_markets\|price_areas" --include='*.php' --include='*.js' --include='*.sql' . 2>/dev/null | grep -v '^./.claude/' | wc -l)
[ "$count" -eq 0 ]; report $? "no references to non-existent tables (found $count)"

# Every table the code touches must exist in the schema of record.
missing=""
for table in onboarding_applications markets price_overrides admin_users \
             admin_login_attempts crowdsourced_prices districts crops sellers \
             buyers seller_contact_details buyer_contact_details; do
    grep -q "CREATE TABLE IF NOT EXISTS \`$table\`\|CREATE TABLE \`$table\`" p601229_AgroBusiness_MW.sql || missing="$missing $table"
done
[ -z "$missing" ]; report $? "schema of record covers every table (missing:${missing:- none})"

# No credential may be committed. Documentation placeholders are fine
# (DB_PASS=..., DB_PASS=your_password, DB_PASS=<value>); a value that looks real
# is not. Checked with one PCRE, because mixing -E and -P silently disables the
# lookahead and turns this whole gate into a no-op.
secret_re='(DB_PASS|DB_PASSWORD|ADMIN_PASSWORD|SMTP_PASS|ADMIN_TOKEN)\s*=\s*(?!\.\.\.|<|your|YOUR|xxx|XXX|$)\S{6,}'
offenders=$(git ls-files -z \
    | xargs -0 grep -lP "$secret_re" 2>/dev/null \
    | grep -v '^tests/run.sh$')
count=$(printf '%s' "$offenders" | grep -c . )
[ "$count" -eq 0 ]; report $? "no committed credential (found $count file(s)${offenders:+: $(echo $offenders)})"

git ls-files --error-unmatch .env >/dev/null 2>&1 && tracked=1 || tracked=0
[ "$tracked" -eq 0 ]; report $? ".env is not tracked by git"

# The standalone controllers must not hardcode a user-facing string outside
# their copy tables — that is how register.js and the directory ended up
# English-only in the first place.
count=$(grep -nE "(setError|textContent|placeholder|innerHTML) *=? *'[A-Z][a-z]+ [a-z]" \
    assets/js/register.js assets/js/directory-navigation.js 2>/dev/null | wc -l)
[ "$count" -eq 0 ]; report $? "no hardcoded UI strings in the standalone controllers (found $count)"

# Scripts referenced by a page must exist on disk.
missing=""
while IFS= read -r ref; do
    [ -f "$ref" ] || missing="$missing $ref"
done < <(grep -rhoE '(assets/(js|css)/[a-z0-9_.-]+\.(js|css))' --include='*.php' . 2>/dev/null | grep -v '^./.claude/' | sort -u)
[ -z "$missing" ]; report $? "every referenced asset exists (missing:${missing:- none})"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
