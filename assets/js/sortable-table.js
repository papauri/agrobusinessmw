/*
 * sortable-table.js — Excel-style sorting plus a compact audit disclosure for
 * the admin community-price review queue.
 */
(function () {
    'use strict';

    if (!document.getElementById('sortable-table-styles')) {
        var css = document.createElement('style');
        css.id = 'sortable-table-styles';
        css.textContent =
            'table.sortable th{cursor:pointer;user-select:none;white-space:nowrap;position:relative}' +
            'table.sortable th[data-no-sort]{cursor:default}' +
            'table.sortable th:not([data-no-sort])::after{content:"\\2195";opacity:.35;margin-left:.4em;font-size:.85em;font-weight:400}' +
            'table.sortable th[aria-sort="ascending"]::after{content:"\\2191";opacity:1}' +
            'table.sortable th[aria-sort="descending"]::after{content:"\\2193";opacity:1}' +
            'table.sortable th:not([data-no-sort]):hover{filter:brightness(1.15)}' +
            '.admin-audit-details{margin-top:.45rem;font-size:.75rem;line-height:1.45;color:#6b5f52}' +
            '.admin-audit-details summary{cursor:pointer;color:#8B7355;font-weight:700;list-style:none}' +
            '.admin-audit-details summary::-webkit-details-marker{display:none}' +
            '.admin-audit-details summary::before{content:"↗";display:inline-block;margin-right:.3rem}' +
            '.admin-audit-grid{display:grid;grid-template-columns:repeat(2,minmax(120px,1fr));gap:.25rem .65rem;margin-top:.45rem;padding:.55rem;background:#faf8f4;border:1px solid #eee9e1;border-radius:7px}' +
            '.admin-audit-grid b{color:#3e3930}';
        (document.head || document.documentElement).appendChild(css);
    }

    function sortKey(row, index) {
        var cell = row.children[index];
        if (!cell) return '';
        if (cell.dataset && cell.dataset.sortValue !== undefined) return cell.dataset.sortValue;
        return (cell.textContent || '').trim();
    }
    function toNumber(value) {
        if (value === '' || value === '—' || value == null) return null;
        var n = parseFloat(String(value).replace(/[^0-9.\-]/g, ''));
        return isNaN(n) ? null : n;
    }
    function sortTable(table, index, th) {
        var headerCells = th.parentNode.children, headerCount = headerCells.length, tbody = table.tBodies[0];
        if (!tbody) return;
        var sortable = [], pinned = [];
        Array.prototype.forEach.call(tbody.rows, function (row) {
            if (row.children.length === headerCount && !row.hasAttribute('data-no-sort')) sortable.push(row); else pinned.push(row);
        });
        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
        Array.prototype.forEach.call(headerCells, function (h) { h.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', dir);
        var factor = dir === 'ascending' ? 1 : -1;
        var numeric = sortable.length > 0 && sortable.every(function (row) { var v = sortKey(row, index); return v === '' || v === '—' || toNumber(v) !== null; });
        sortable.sort(function (a, b) {
            var va = sortKey(a, index), vb = sortKey(b, index);
            if (numeric) { var na = toNumber(va), nb = toNumber(vb); if (na === null) na = -Infinity; if (nb === null) nb = -Infinity; return (na - nb) * factor; }
            return va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' }) * factor;
        });
        sortable.forEach(function (row) { tbody.appendChild(row); });
        pinned.forEach(function (row) { tbody.appendChild(row); });
    }

    function addPriceAudit() {
        var table = document.getElementById('prices-table');
        if (!table || table.dataset.auditReady === '1') return;
        table.dataset.auditReady = '1';
        Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
            var cells = row.children;
            if (cells.length < 10) return;
            var details = document.createElement('details');
            details.className = 'admin-audit-details';
            var summary = document.createElement('summary');
            summary.textContent = 'Audit details';
            var grid = document.createElement('div'); grid.className = 'admin-audit-grid';
            function add(label, value) { var item = document.createElement('div'); var b = document.createElement('b'); b.textContent = label + ': '; item.appendChild(b); item.appendChild(document.createTextNode(value)); grid.appendChild(item); }
            add('What', cells[0].textContent.trim()); add('Where', cells[1].textContent.trim().replace(/\s+/g, ' ')); add('Price/kg', cells[2].textContent.trim()); add('Price/bag', cells[3].textContent.trim()); add('Who', cells[4].textContent.trim()); add('Channel', cells[5].textContent.trim()); add('Member', cells[6].textContent.trim()); add('Status', cells[7].textContent.trim().replace(/\s+/g, ' ')); add('When', cells[8].textContent.trim());
            details.appendChild(summary); details.appendChild(grid); cells[9].insertBefore(details, cells[9].firstChild);
        });
        var heading = table.previousElementSibling;
        if (heading && !document.getElementById('price-audit-link')) {
            var link = document.createElement('a'); link.id = 'price-audit-link'; link.href = 'price-audit.php'; link.textContent = ' View full audit history →';
            link.style.cssText = 'font-size:.78rem;color:#8B7355;margin-left:.75rem;font-weight:600;text-decoration:none;'; heading.appendChild(link);
        }
    }

    // The legacy page's inline handler writes reviewed_by='admin'. Intercept the
    // browser action and route it through the identity-aware endpoint instead.
    function bindPriceReviewForms() {
        var table = document.getElementById('prices-table');
        if (!table || table.dataset.reviewReady === '1') return;
        table.dataset.reviewReady = '1';
        Array.prototype.forEach.call(table.querySelectorAll('form'), function (form) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                var submitter = event.submitter;
                if (!submitter || !submitter.name || submitter.name !== 'price_action') return;

                var formData = new FormData(form);
                formData.set('price_action', submitter.value);
                submitter.disabled = true;

                try {
                    var response = await fetch('price-review.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' },
                        body: formData
                    });
                    var result = await response.json();
                    if (!response.ok || !result.ok) {
                        throw new Error(result.error || 'The review could not be saved.');
                    }
                    window.location.reload();
                } catch (error) {
                    submitter.disabled = false;
                    window.alert(error.message || 'The review could not be saved.');
                }
            });
        });
    }

    document.addEventListener('click', function (e) {
        var th = e.target.closest && e.target.closest('th');
        if (!th || th.hasAttribute('data-no-sort')) return;
        var table = th.closest('table');
        if (!table || !table.classList.contains('sortable')) return;
        var index = Array.prototype.indexOf.call(th.parentNode.children, th);
        if (index >= 0) sortTable(table, index, th);
    });

    function init() {
        addPriceAudit();
        bindPriceReviewForms();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
