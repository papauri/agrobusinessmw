<?php
/** Shared script includes. */
$service = $service ?? null;
?>
<script>window.AGRO_PAGE = <?= $service ? json_encode($service) : 'null' ?>;</script>
<script>
(function(){
    function nav(){return document.getElementById('app-nav');}
    function open(){const n=nav();if(!n)return;n.classList.add('open');n.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';}
    function close(){const n=nav();if(!n)return;n.classList.remove('open');n.setAttribute('aria-hidden','true');document.body.style.overflow='';}
    document.addEventListener('click',function(e){if(e.target.closest('.nav-toggle')){e.preventDefault();open();return;}if(e.target.closest('[data-nav-close]'))close();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
    document.addEventListener('DOMContentLoaded',function(){const here=(location.pathname.split('/').pop()||'index.php');document.querySelectorAll('#app-nav .app-nav-link').forEach(function(a){const key=a.getAttribute('data-nav');if((here===''||here==='index.php')?key==='home':key===here)a.classList.add('is-current');});});
})();
</script>
<?php
/* Script load order matters and is deliberate.
 *
 *  1. config.js, i18n.js and phone-normalizer.js are plain scripts: they define
 *     globals (AGRO_CONFIG, AgroLang, AgroPhone) that later modules read on
 *     first use. i18n.js must precede anything that renders a user-facing
 *     string, and phone-normalizer.js reads AgroLang for its error message.
 *  2. app.js is deferred and defines window.app.
 *  3. The page controllers are deferred and therefore run after app.js, in
 *     document order. Each one owns exactly one page and checks AGRO_PAGE or
 *     the pathname before doing anything.
 *
 * Registration is not in this list: register.php loads its own bundle and does
 * not include app.js at all. */
?>
<script src="assets/js/config.js"></script>
<script src="assets/js/i18n.js?v=20260816-0002"></script>
<script src="assets/js/phone-normalizer.js?v=20260816-0002"></script>
<script src="assets/js/app.js?v=20260817-0001" defer></script>
<script src="assets/js/directory-navigation.js?v=20260817-0001" defer></script>
<script src="assets/js/market-insights-page.js?v=20260816-0001" defer></script>
<script src="assets/js/sortable-table.js" defer></script>
<script src="assets/js/price-report-story.js" defer></script>
<script src="assets/js/quiet-db-notification.js" defer></script>
<link rel="stylesheet" href="assets/css/price-report-story.css">
<link rel="stylesheet" href="assets/css/district-theme.css">
<link rel="stylesheet" href="assets/css/directory-navigation.css?v=20260817-0001">
<link rel="stylesheet" href="assets/css/market-insights-page.css?v=20260816-0001">
<script>
window.addEventListener('load',function(){
    if('serviceWorker' in navigator){const u=new URL('sw.js',window.location.href).href;navigator.serviceWorker.getRegistrations().then(r=>r.forEach(x=>{if(x.scriptURL===u)x.unregister();})).catch(()=>{});}
});
</script>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebApplication","name":"AgroBusiness Malawi","description":"Smart agricultural platform for Malawian farmers with live weather, crop prices, and market insights","url":"https://agrobusinessmw.com","applicationCategory":"BusinessApplication","operatingSystem":"Any","offers":{"@type":"Offer","price":"0","priceCurrency":"USD"},"author":{"@type":"Organization","name":"AgroBusiness Malawi"}}</script>
