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
python3 tests/i18n_parity.py;      report $? "en/ci key parity across all translation tables"
php tests/ussd_menu_parity.php;    report $? "en/ci parity across the USSD menus"

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


# Every table the code touches must exist in the schema of record.
missing=""
for table in admin_login_attempts admin_users basic_farming_info \
             buyer_contact_details buyer_crops buyers community_qa crop_prices \
             crops crowdsourced_prices districts farming_best_practices \
             market_insights markets onboarding_applications pest_control_tips \
             price_areas price_markets price_overrides price_review_audit \
             ratings seller_contact_details seller_crops sellers; do
    grep -q "CREATE TABLE IF NOT EXISTS \`$table\`\|CREATE TABLE \`$table\`" p601229_AgroBusiness_MW.sql || missing="$missing $table"
done
[ -z "$missing" ]; report $? "schema of record covers all 24 production tables (missing:${missing:- none})"

# Every table the code touches must be in the schema of record. This is the check
# that would have caught price_review_audit: admin/price-audit.php reads it, but
# the schema file did not create it, so a fresh restore broke that page.
missing=""
for table in $(grep -rhoE '(FROM|JOIN|INTO|UPDATE|TABLE IF NOT EXISTS) `?[a-z_]+`?' \
                  --include='*.php' . 2>/dev/null \
              | grep -v '^./.claude/' \
              | awk '{print $2}' | tr -d '`' | sort -u); do
    case "$table" in
        admin_login_attempts|admin_users|basic_farming_info|buyer_contact_details|\
        buyer_crops|buyers|community_qa|crop_prices|crops|crowdsourced_prices|\
        districts|farming_best_practices|market_insights|markets|\
        onboarding_applications|pest_control_tips|price_areas|price_markets|\
        price_overrides|price_review_audit|ratings|seller_contact_details|\
        seller_crops|sellers)
            grep -q "CREATE TABLE.*\`$table\`" p601229_AgroBusiness_MW.sql || missing="$missing $table" ;;
    esac
done
[ -z "$missing" ]; report $? "every table referenced by PHP is in the schema (missing:${missing:- none})"

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

# The farmer roster is the one public listing built straight out of
# onboarding_applications, and that table holds phone_number, whatsapp_number,
# email and national_id in the columns either side of the ones it reads. Nobody
# who registered as a farmer agreed to have their number published (privacy.php
# §3 says so explicitly), so one careless `SELECT oa.*` is a real leak. These two
# gates read the shipped query text rather than trusting the comment above it.
farmers_block=$(sed -n "/case 'farmers':/,/^            break;/p" api.php)
count=$(printf '%s\n' "$farmers_block" | grep -cE 'phone_number|whatsapp_number|national_id|oa\.email|oa\.\*')
[ "$count" -eq 0 ]; report $? "the farmers query selects no contact column (found $count)"

printf '%s\n' "$farmers_block" | grep -q "oa.status = 'approved'"
report $? "the farmers query lists only approved registrations"

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
