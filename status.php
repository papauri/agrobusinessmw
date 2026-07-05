<?php
$service   = 'status';
$pageTitle = 'Check Application Status — AgroBusiness Malawi';
$pageDesc  = 'Check the status of your AgroBusiness Malawi registration application.';
$introHtml = '
<div class="page-intro">
    <div class="page-intro-icon">🔎</div>
    <h2>Check Your Application</h2>
    <p>Enter the reference number you received when you registered to see whether your application is pending, approved or denied.</p>
    <div class="page-intro-actions">
        <button class="btn-primary" onclick="var m=document.getElementById(\'status-modal\');if(m&&window.app){app.openModal(m);}">Check Status</button>
        <a class="btn-secondary" href="register.php">New registration</a>
    </div>
</div>';
include __DIR__ . '/partials/function-page.php';
