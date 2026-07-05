<?php
$service   = 'register';
$pageTitle = 'Register — AgroBusiness Malawi';
$pageDesc  = 'Register as a farmer, seller or buyer on AgroBusiness Malawi.';
$introHtml = '
<div class="page-intro">
    <div class="page-intro-icon">📝</div>
    <h2>Join AgroBusiness Malawi</h2>
    <p>Register as a Farmer, Seller or Buyer to connect with markets, list your produce and access trading opportunities across all 28 districts.</p>
    <div class="page-intro-actions">
        <button class="btn-primary" onclick="if(window.app){app.openRegistrationModal();}">Start Registration</button>
        <a class="btn-secondary" href="status.php">Check application status</a>
    </div>
</div>';
include __DIR__ . '/partials/function-page.php';
