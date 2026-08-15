/* Registration is a standalone page. Keep the dashboard as an entry point only. */
(function () {
  function install() {
    if (!window.app || typeof window.app.openService !== 'function') return false;
    if (window.app.__registrationPageHookInstalled) return true;
    const original = window.app.openService.bind(window.app);
    window.app.openService = function (service) {
      if (service === 'register') {
        window.location.href = 'register.php';
        return;
      }
      return original(service);
    };
    window.app.__registrationPageHookInstalled = true;
    return true;
  }
  if (install()) return;
  window.addEventListener('load', install, { once: true });
  document.addEventListener('DOMContentLoaded', install, { once: true });
})();
