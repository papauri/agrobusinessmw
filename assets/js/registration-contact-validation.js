/* AgroBusiness Malawi — registration contact validation.
 * Keeps the existing registration workflow, but makes phone + WhatsApp
 * canonical before the existing application submission runs.
 */
(function () {
    'use strict';

    const phoneApi = () => window.AgroPhone;

    function get(id) {
        return document.getElementById(id);
    }

    function addWhatsAppField() {
        if (get('reg-whatsapp')) return;
        const phone = get('reg-phone');
        if (!phone || !phone.parentElement) return;

        const group = document.createElement('div');
        group.className = 'form-group reg-whatsapp-group';
        group.innerHTML = `
            <label for="reg-whatsapp">WhatsApp Number <span class="optional-label">(optional)</span></label>
            <input type="tel" id="reg-whatsapp" placeholder="0888 123 456 or +265 888 123 456"
                   autocomplete="tel" inputmode="tel" maxlength="20"
                   aria-describedby="reg-whatsapp-help">
            <small id="reg-whatsapp-help" class="form-help">Use the number connected to WhatsApp. Malawi local numbers are converted automatically.</small>
        `;
        phone.parentElement.insertAdjacentElement('afterend', group);
    }

    function clearFieldError(field) {
        if (!field) return;
        field.classList.remove('is-invalid');
        const old = field.parentNode && field.parentNode.querySelector('.registration-contact-error');
        if (old) old.remove();
    }

    function setFieldError(field, message) {
        if (!field) return;
        clearFieldError(field);
        field.classList.add('is-invalid');
        const error = document.createElement('span');
        error.className = 'field-error registration-contact-error';
        error.textContent = message;
        field.parentNode.appendChild(error);
    }

    function normalizeField(id, required, label) {
        const field = get(id);
        if (!field) return { value: '', valid: !required };
        const value = field.value.trim();
        clearFieldError(field);
        if (!value) {
            if (required) {
                setFieldError(field, `${label} is required.`);
                return { value: '', valid: false };
            }
            return { value: '', valid: true };
        }

        const api = phoneApi();
        const normalized = api && typeof api.normalize === 'function' ? api.normalize(value) : null;
        if (!normalized) {
            setFieldError(field, `${label} is not valid. Try 0888 123 456 or +265 888 123 456.`);
            return { value: '', valid: false };
        }
        field.value = normalized;
        return { value: normalized, valid: true };
    }

    function normalizeContacts() {
        const phone = normalizeField('reg-phone', true, 'Phone number');
        const whatsapp = normalizeField('reg-whatsapp', false, 'WhatsApp number');
        return { phone, whatsapp, valid: phone.valid && whatsapp.valid };
    }

    async function checkDuplicates(phone, whatsapp, email, nationalId, fullName) {
        const params = new URLSearchParams({
            phone,
            whatsapp_number: whatsapp,
            email,
            national_id: nationalId,
            full_name: fullName
        });
        const response = await fetch('api.php?action=check_duplicate&' + params.toString());
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    }

    async function submitApplicationWithContacts() {
        const app = window.app;
        if (!app) return;

        const state = app._regState;
        const contacts = normalizeContacts();
        if (!contacts.valid) return;

        const fullName = get('reg-full-name').value.trim();
        const email = get('reg-email').value.trim();
        const nationalId = get('reg-national-id').value.trim();
        const districtId = get('reg-district').value;
        const village = get('reg-village').value.trim();
        const selectedCrops = [...document.querySelectorAll('#reg-crops-grid input:checked')]
            .map(el => el.dataset.name).join(', ');
        const business = get('reg-business-name')?.value.trim() || '';

        const submit = get('reg-submit-btn');
        if (!submit) return;
        submit.disabled = true;
        submit.textContent = 'Submitting…';

        try {
            const res = await fetch('api.php?action=submit_application', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_type: state.userType,
                    full_name: fullName,
                    phone_number: contacts.phone.value,
                    email,
                    national_id: nationalId,
                    district_id: districtId ? parseInt(districtId, 10) : null,
                    village,
                    crops_of_interest: selectedCrops,
                    business_name: business,
                    channel: 'web'
                })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Submission failed.');

            // The existing application endpoint creates the application and sends
            // its normal notifications. This second, tightly scoped endpoint stores
            // the optional WhatsApp value with the same canonical validation.
            const contactRes = await fetch('registration-contact.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    application_ref: data.ref,
                    phone_number: contacts.phone.value,
                    whatsapp_number: contacts.whatsapp.value || null
                })
            });
            const contactData = await contactRes.json();
            if (!contactData.success) {
                throw new Error(contactData.error || 'Contact details could not be saved.');
            }

            document.querySelectorAll('.reg-step-content').forEach(el => el.style.display = 'none');
            const successEl = get('reg-step-success');
            if (successEl) successEl.style.display = '';
            const refEl = get('reg-ref-number');
            if (refEl) refEl.textContent = data.ref;
            document.querySelectorAll('.reg-steps .reg-step').forEach(el => {
                el.classList.remove('reg-step-active');
                el.classList.add('reg-step-done');
            });
        } catch (error) {
            console.error('Registration contact submission failed:', error);
            if (app) app.showNotification(error.message || 'Registration failed. Please try again.', 'error');
            submit.disabled = false;
            submit.textContent = 'Submit Application ✓';
        }
    }

    function install() {
        addWhatsAppField();

        // The normalizer is loaded before this file. Keep the existing app flow,
        // but replace the two weak registration checks with one capture-phase gate.
        document.addEventListener('click', async function (event) {
            const target = event.target.closest ? event.target.closest('#reg-step2-next') : null;
            if (!target) return;

            const modal = get('register-modal');
            if (!modal) return;
            addWhatsAppField();

            // Stop the old step-2 handler. We run the same validation plus WhatsApp
            // and duplicate checking, then advance only when everything is valid.
            event.preventDefault();
            event.stopImmediatePropagation();

            const name = get('reg-full-name').value.trim();
            const email = get('reg-email').value.trim();
            const nationalId = get('reg-national-id').value.trim();
            const district = get('reg-district').value;
            const village = get('reg-village').value.trim();
            const contacts = normalizeContacts();

            if (!name || name.length < 2) setFieldError(get('reg-full-name'), 'Full name is required (at least 2 characters).');
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) setFieldError(get('reg-email'), 'Enter a valid email address or leave blank.');
            if (!village || village.length < 2) setFieldError(get('reg-village'), 'Village or town is required.');
            if (!district) setFieldError(get('reg-district'), 'Please select your district.');

            const validText = name.length >= 2 && (!email || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) && village.length >= 2 && !!district;
            if (!contacts.valid || !validText) return;

            target.disabled = true;
            const original = target.textContent;
            target.textContent = 'Checking…';
            try {
                const result = await checkDuplicates(contacts.phone.value, contacts.whatsapp.value, email, nationalId, name);
                const matches = result.matches || [];
                const hard = matches.filter(m => m.hard);
                if (hard.length) {
                    hard.forEach(m => {
                        const fieldId = m.field === 'whatsapp' ? 'reg-whatsapp' : m.field === 'national_id' ? 'reg-national-id' : m.field === 'name' ? 'reg-full-name' : 'reg-' + m.field;
                        setFieldError(get(fieldId), `This ${m.field === 'whatsapp' ? 'WhatsApp number' : m.field} is already registered (Ref ${m.ref} · ${m.status}).`);
                    });
                    return;
                }
                const soft = matches.filter(m => !m.hard);
                if (soft.length && !window.app._regNameWarned) {
                    window.app._regNameWarned = true;
                    setFieldError(get('reg-full-name'), 'Someone with this name has already applied. Press Next again if this is a different person.');
                    return;
                }
                window.app._regGotoStep(3);
            } catch (error) {
                // Preserve the existing fail-open behaviour for a temporary network
                // issue; submit-time server validation remains authoritative.
                window.app._regGotoStep(3);
            } finally {
                target.disabled = false;
                target.textContent = original;
            }
        }, true);

        document.addEventListener('click', function (event) {
            const target = event.target.closest ? event.target.closest('#reg-submit-btn') : null;
            if (!target) return;
            const modal = get('register-modal');
            if (!modal) return;
            addWhatsAppField();
            event.preventDefault();
            event.stopImmediatePropagation();
            submitApplicationWithContacts();
        }, true);

        // Clear contact errors as users correct their numbers.
        ['reg-phone', 'reg-whatsapp'].forEach(id => {
            const field = get(id);
            if (field) field.addEventListener('input', () => clearFieldError(field));
        });

        // Re-run the injection/reset after every opening of the registration modal.
        if (window.app && typeof window.app.openRegistrationModal === 'function') {
            const originalOpen = window.app.openRegistrationModal.bind(window.app);
            window.app.openRegistrationModal = function () {
                originalOpen();
                addWhatsAppField();
                const whatsapp = get('reg-whatsapp');
                if (whatsapp) {
                    whatsapp.value = '';
                    clearFieldError(whatsapp);
                }
            };
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
    else install();
})();
