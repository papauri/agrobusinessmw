<?php /* Shared modals used across all pages: district/crop pickers, overviews, registration, status, admin. */ ?>
<!-- District Selection Modal -->
<div id="district-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="district-modal-title">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="district-modal-title" data-text="select_district">Select Your District</h2>
            <button class="modal-close" aria-label="Close"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
            <div class="district-picker-container">
                <div class="search-box"><span class="search-icon material-symbols-rounded">search</span><input type="text" id="district-search" placeholder="Search district or region..." aria-label="Search districts"></div>
                <p id="search-stats" class="selection-count">Districts loading</p>
                <div id="district-list" class="district-picker-list" aria-live="polite"></div>
            </div>
        </div>
    </div>
</div>

<!-- Crop Selection Modal -->
<div id="crop-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="crop-modal-title">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="crop-modal-title" data-text="select_crop">Select Your Crop</h2>
            <button class="modal-close" aria-label="Close"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body">
            <div class="search-box"><span class="search-icon material-symbols-rounded">search</span><input type="text" id="crop-search" placeholder="Search crops..." aria-label="Search crops"></div>
            <p id="crop-search-stats" class="selection-count">Crops loading</p>
            <div id="crop-list" class="item-list" aria-live="polite"></div>
        </div>
    </div>
</div>

<!-- Districts Overview Modal -->
<div id="districts-overview-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="districts-overview-title">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="districts-overview-title">All Districts</h2>
            <button class="modal-close" aria-label="Close"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body"><div id="districts-overview-content" class="overview-grid" aria-live="polite"></div></div>
    </div>
</div>

<!-- Crops Overview Modal -->
<div id="crops-overview-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="crops-overview-title">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="crops-overview-title">All Crops</h2>
            <button class="modal-close" aria-label="Close"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body"><div id="crops-overview-content" class="overview-grid" aria-live="polite"></div></div>
    </div>
</div>

<!-- Registration Modal -->
<div id="register-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="register-modal-title">
    <div class="modal-backdrop"></div>
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2 id="register-modal-title">Register</h2>
            <button class="modal-close" id="register-modal-close" aria-label="Close"><span class="material-symbols-rounded">close</span></button>
        </div>
        <div class="modal-body" id="register-modal-body">
            <div class="reg-steps"><div class="reg-step active" data-step="1">1</div><div class="reg-step" data-step="2">2</div><div class="reg-step" data-step="3">3</div><div class="reg-step" data-step="4">4</div></div>
            <div id="reg-step-1" class="reg-step-content">
                <p class="reg-label">I am registering as a:</p>
                <div class="reg-options"><button class="reg-option" data-type="farmer"><span>🧑‍🌾</span><strong>Farmer</strong></button><button class="reg-option" data-type="seller"><span>🏪</span><strong>Seller</strong></button><button class="reg-option" data-type="buyer"><span>🏢</span><strong>Buyer</strong></button></div>
                <div class="reg-link"><button class="reg-check-status" id="reg-check-status-btn">Already applied? Check status</button></div>
            </div>
            <div id="reg-step-2" class="reg-step-content" style="display: none;">
                <div class="form-group"><label>Full Name *</label><input type="text" id="reg-full-name" placeholder="John Banda" autocomplete="name"></div>
                <div class="form-group"><label>Phone Number *</label><input type="tel" id="reg-phone" placeholder="0888 123 456 or +265 888 123 456" autocomplete="tel" inputmode="tel" maxlength="20" aria-describedby="reg-phone-help"><small id="reg-phone-help" class="form-help">You can enter a Malawi number as 0888 123 456. It will be saved securely in international format.</small></div>
                <div class="form-group"><label>Email (optional)</label><input type="email" id="reg-email" placeholder="john@example.com" autocomplete="email"></div>
                <div class="form-group"><label>National ID</label><input type="text" id="reg-national-id" placeholder="12345678" autocomplete="off"></div>
                <div class="form-group"><label>Village / Town *</label><input type="text" id="reg-village" placeholder="Lilongwe" autocomplete="address-level2"></div>
                <div class="form-group"><label>District *</label><select id="reg-district"><option value="">— Select District —</option></select></div>
                <div class="reg-nav"><button class="btn-secondary reg-back-btn" data-goto="1">← Back</button><button class="btn-primary" id="reg-step2-next">Next →</button></div>
            </div>
            <div id="reg-step-3" class="reg-step-content" style="display: none;">
                <div class="form-group"><label>Crops you grow / trade</label><div id="reg-crops-grid" class="crops-grid"></div></div>
                <div class="form-group" id="reg-business-field" style="display: none;"><label>Business Name</label><input type="text" id="reg-business-name" placeholder="Your business"></div>
                <div class="reg-nav"><button class="btn-secondary reg-back-btn" data-goto="2">← Back</button><button class="btn-primary" id="reg-step3-next">Review →</button></div>
            </div>
            <div id="reg-step-4" class="reg-step-content" style="display: none;">
                <div id="reg-review-content" class="review-box"></div>
                <p class="review-note">By submitting you agree to KYC verification. We will review and notify you within 2–3 business days.</p>
                <div class="reg-nav"><button class="btn-secondary reg-back-btn" data-goto="3">← Back</button><button class="btn-primary" id="reg-submit-btn">Submit ✓</button></div>
            </div>
            <div id="reg-step-success" class="reg-step-content success-msg" style="display: none;">
                <div class="success-icon">✓</div><h3>Application Submitted</h3><p>Reference Number:</p><div id="reg-ref-number" class="ref-number"></div><p class="success-note">Save this reference. We will review and notify you.</p>
                <button class="btn-primary" onclick="document.getElementById('register-modal').classList.remove('active');document.body.style.overflow='';">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Status Check Modal -->
<div id="status-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="status-modal-title">
    <div class="modal-backdrop"></div>
    <div class="modal-content" style="max-width: 420px;">
        <div class="modal-header"><h2 id="status-modal-title">Check Status</h2><button class="modal-close" id="status-modal-close" aria-label="Close"><span class="material-symbols-rounded">close</span></button></div>
        <div class="modal-body"><div class="form-group"><label>Reference Number</label><input type="text" id="status-ref-input" placeholder="AGR-20241201-A3F7B" style="text-transform: uppercase;"></div><button class="btn-primary" id="status-check-btn" style="width: 100%;">Check Status</button><div id="status-result" style="margin-top: 1.5rem; display: none;"></div></div>
    </div>
</div>
