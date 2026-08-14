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
<script src="assets/js/directory-navigation.js?v=20260815-0001"></script>
<script src="assets/js/app.js" defer></script>
<script src="assets/js/directory-home-hook.js?v=20260815-0001"></script>
<script src="assets/js/config.js"></script>
<script src="assets/js/sortable-table.js" defer></script>
<script src="assets/js/price-report-story.js" defer></script>
<script src="assets/js/price-location-selector.js" defer></script>
<script src="assets/js/phone-normalizer.js" defer></script>
<script src="assets/js/quiet-db-notification.js" defer></script>
<link rel="stylesheet" href="assets/css/price-report-story.css">
<link rel="stylesheet" href="assets/css/price-location-selector.css">
<link rel="stylesheet" href="assets/css/district-theme.css">
<link rel="stylesheet" href="assets/css/directory-navigation.css">
<script>
window.addEventListener('load',function(){
    if('serviceWorker' in navigator){const u=new URL('sw.js',window.location.href).href;navigator.serviceWorker.getRegistrations().then(r=>r.forEach(x=>{if(x.scriptURL===u)x.unregister();})).catch(()=>{});}
});
</script>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebApplication","name":"AgroBusiness Malawi","description":"Smart agricultural platform for Malawian farmers with live weather, crop prices, and market insights","url":"https://agrobusinessmw.com","applicationCategory":"BusinessApplication","operatingSystem":"Any","offers":{"@type":"Offer","price":"0","priceCurrency":"USD"},"author":{"@type":"Organization","name":"AgroBusiness Malawi"}}</script>
