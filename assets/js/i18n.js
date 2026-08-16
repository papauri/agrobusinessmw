/* AgroBusiness Malawi — shared language state.
 *
 * The app has always stored the reader's language in localStorage under
 * `preferredLanguage` ('en' or 'ci'), written by app.js's setLanguage(). This
 * file does NOT introduce a second source of truth — it reads and writes that
 * same key, so a language chosen on the dashboard is the language the
 * registration page and the directory come up in.
 *
 * It exists because register.php does not load app.js at all (registration is
 * standalone), and without it every page that needs the language would carry
 * its own copy of "read localStorage, fall back to English".
 *
 * Usage:
 *   AgroLang.current()                     -> 'en' | 'ci'
 *   AgroLang.pick({ en: 'Home', ci: '…' }) -> the string for the current language
 *   AgroLang.set('ci')                     -> persist + notify
 *   AgroLang.onChange(fn)                  -> called with the new language
 *
 * Pages re-render on change rather than reloading, so switching language never
 * loses what the user has already typed into a form.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'preferredLanguage';
    const SUPPORTED = ['en', 'ci'];
    const DEFAULT = 'en';
    const EVENT = 'agro:langchange';

    function read() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            return SUPPORTED.indexOf(stored) !== -1 ? stored : DEFAULT;
        } catch (e) {
            // Private mode / blocked storage. English is a working fallback.
            return DEFAULT;
        }
    }

    let current = read();

    function applyDocumentLang(lang) {
        const root = document.documentElement;
        if (!root) return;
        root.setAttribute('lang', lang === 'ci' ? 'ny' : 'en');
        root.setAttribute('data-user-lang', lang);
    }

    applyDocumentLang(current);

    function set(lang) {
        if (SUPPORTED.indexOf(lang) === -1 || lang === current) return current;
        current = lang;
        try { localStorage.setItem(STORAGE_KEY, lang); } catch (e) { /* not fatal */ }
        applyDocumentLang(lang);
        document.dispatchEvent(new CustomEvent(EVENT, { detail: { lang: lang } }));
        return current;
    }

    window.AgroLang = {
        SUPPORTED: SUPPORTED.slice(),
        current: function () { return current; },
        set: set,
        toggle: function () { return set(current === 'en' ? 'ci' : 'en'); },

        /* Pick a value out of a { en, ci } object, falling back to English so a
           missing translation shows the English string rather than nothing. */
        pick: function (dict) {
            if (!dict) return '';
            const value = dict[current];
            return value === undefined || value === null ? (dict[DEFAULT] || '') : value;
        },

        onChange: function (handler) {
            document.addEventListener(EVENT, function (event) {
                handler(event.detail ? event.detail.lang : current);
            });
        },

        /* app.js writes the key directly when the dashboard switcher is used.
           Re-read it so a page that was open in another tab catches up. */
        refresh: function () {
            const stored = read();
            if (stored !== current) set(stored);
            return current;
        }
    };

    // Another tab changed the language.
    window.addEventListener('storage', function (event) {
        if (event.key === STORAGE_KEY) window.AgroLang.refresh();
    });
})();
