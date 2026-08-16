<?php /* Shared modals used across all pages: district and crop pickers, the district
   and crop overviews, and the application-status lookup.

   There is deliberately NO registration modal here. Registration lives entirely in
   register.php + assets/js/register.js + assets/css/register.css. A modal copy used
   to sit in this file with the same element ids as the registration page, which meant
   two different validators wrote two different shapes of data to the same table.
   Do not add one back. */ ?>
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

<!-- Status Check Modal -->
<div id="status-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="status-modal-title">
    <div class="modal-backdrop"></div>
    <div class="modal-content" style="max-width: 420px;">
        <div class="modal-header"><h2 id="status-modal-title">Check Status</h2><button class="modal-close" id="status-modal-close" aria-label="Close"><span class="material-symbols-rounded">close</span></button></div>
        <div class="modal-body">
            <div class="form-group">
                <label for="status-ref-input">Reference number</label>
                <input type="text" id="status-ref-input" class="status-ref-input" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="24" placeholder="AGR-20241201-A3F7B" aria-describedby="status-ref-help">
                <small id="status-ref-help" class="form-help">The reference you were given when you registered.</small>
            </div>
            <button class="btn-primary status-check-btn" id="status-check-btn" type="button">Check status</button>
            <div id="status-result" class="status-result" role="status" hidden></div>
        </div>
    </div>
</div>
