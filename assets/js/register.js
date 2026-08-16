/* AgroBusiness Malawi — registration controller.
 *
 * The only registration client in the project. It drives register.php's four
 * steps and posts to register.php itself; there is no registration modal and no
 * second submit endpoint. Phone numbers are canonicalised with window.AgroPhone
 * (assets/js/phone-normalizer.js), which implements the same rules as
 * config/phone.php, so the browser and the server never disagree about what a
 * number means.
 *
 * Client-side validation exists to give fast, field-level feedback. It is not a
 * security control: register.php re-validates everything it is sent.
 */
(function () {
  'use strict';

  const FIRST_STEP = 1;
  const LAST_STEP = 4;

  const state = { step: FIRST_STEP, role: '', districts: [], crops: [], submitting: false };

  const qs = (selector) => document.querySelector(selector);
  const qsa = (selector) => Array.from(document.querySelectorAll(selector));
  const byId = (id) => document.getElementById(id);

  const form = byId('registration-form');
  if (!form) return;

  /* ── Phone handling ─────────────────────────────────────────────────────── */

  // phone-normalizer.js is loaded synchronously before this file, so AgroPhone is
  // always present. Rather than carry a second, subtly different copy of the
  // rules as a fallback, fail loudly — a divergent normaliser writes wrong
  // numbers into the database, which is worse than not submitting at all.
  function normalizePhone(value) {
    if (!window.AgroPhone || typeof window.AgroPhone.normalize !== 'function') {
      throw new Error('Phone number checking is unavailable. Please reload the page.');
    }
    return window.AgroPhone.normalize(value);
  }

  const PHONE_HELP = 'Enter a Malawi number as 0888 123 456, or an international number with its country code, e.g. +44 7700 900123.';

  /* ── Errors ─────────────────────────────────────────────────────────────── */

  function setError(field, message) {
    const el = byId('error-' + field);
    if (el) el.textContent = message || '';
    const input = form.querySelector('[name="' + field + '"]');
    if (input) {
      input.classList.toggle('is-invalid', Boolean(message));
      if (message) input.setAttribute('aria-invalid', 'true');
      else input.removeAttribute('aria-invalid');
    }
  }

  function clearErrors() {
    qsa('.register-error').forEach(el => { el.textContent = ''; });
    qsa('.is-invalid').forEach(el => { el.classList.remove('is-invalid'); el.removeAttribute('aria-invalid'); });
  }

  // Put the caret where the problem is, and make sure the message is on screen.
  function focusField(field) {
    const input = form.querySelector('[name="' + field + '"]');
    if (!input) return;
    input.focus({ preventScroll: true });
    input.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  /* ── Steps ──────────────────────────────────────────────────────────────── */

  function setStep(step) {
    state.step = Math.min(LAST_STEP, Math.max(FIRST_STEP, step));
    qsa('.register-step').forEach(el => {
      const active = Number(el.dataset.step) === state.step;
      el.hidden = !active;
      el.classList.toggle('active', active);
    });
    qsa('[data-progress]').forEach(el => {
      const index = Number(el.dataset.progress);
      el.classList.toggle('active', index <= state.step);
      el.classList.toggle('done', index < state.step);
    });
    const heading = qs('.register-step.active h3');
    if (heading) heading.setAttribute('tabindex', '-1');
    if (heading) heading.focus({ preventScroll: true });
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (state.step === LAST_STEP) buildReview();
  }

  /* ── Reference data ─────────────────────────────────────────────────────── */

  async function getJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (!data.success) throw new Error(data.error || 'Request failed');
    return data;
  }

  async function loadDistricts() {
    const select = byId('reg-district');
    if (!select) return;
    try {
      const data = await getJson('api.php?action=districts');
      state.districts = data.data || [];
      // Built with DOM nodes, not markup: district names are database values and
      // textContent cannot be talked into becoming an element.
      select.textContent = '';
      const placeholder = new Option('— Select district —', '');
      select.appendChild(placeholder);
      state.districts.forEach(district => {
        select.appendChild(new Option(String(district.name), String(district.id)));
      });
    } catch (error) {
      select.textContent = '';
      select.appendChild(new Option('Districts could not be loaded', ''));
      setError('district_id', 'Districts could not be loaded. Check your connection and reload the page.');
    }
  }

  async function loadCrops() {
    const box = byId('reg-crops');
    if (!box) return;
    try {
      const data = await getJson('api.php?action=crops');
      state.crops = data.data || [];
      box.textContent = '';
      if (!state.crops.length) {
        box.textContent = 'No crops are available yet. Please try again later.';
        return;
      }
      state.crops.forEach(crop => {
        const label = document.createElement('label');
        label.className = 'register-crop';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.value = String(crop.id);
        input.name = 'crop_ids';

        const text = document.createElement('span');
        text.textContent = String(crop.name);

        label.append(input, text);
        box.appendChild(label);
      });
    } catch (error) {
      box.textContent = 'Crops could not be loaded. Check your connection and reload the page.';
    }
  }

  function selectedCrops() {
    return qsa('#reg-crops input:checked').map(input => ({
      id: Number(input.value),
      name: input.nextElementSibling ? input.nextElementSibling.textContent : ''
    }));
  }

  /* ── Validation ─────────────────────────────────────────────────────────── */

  function value(id) {
    const el = byId(id);
    return el ? el.value.trim() : '';
  }

  // Returns the contact values when everything is valid, otherwise null after
  // painting every failing field so the user sees all the problems at once.
  function validateDetails() {
    setError('full_name', '');
    setError('phone_number', '');
    setError('whatsapp_number', '');
    setError('email', '');
    setError('national_id', '');
    setError('village', '');
    setError('district_id', '');
    setError('business_name', '');

    let firstBad = '';
    const fail = (field, message) => {
      setError(field, message);
      if (!firstBad) firstBad = field;
    };

    const fullName = value('reg-full-name');
    if (fullName.length < 2) fail('full_name', 'Enter your full name.');
    else if (fullName.length > 150) fail('full_name', 'That name is too long.');

    let phone = null;
    const phoneInput = value('reg-phone');
    if (!phoneInput) fail('phone_number', 'A phone number is required.');
    else {
      phone = normalizePhone(phoneInput);
      if (!phone) fail('phone_number', PHONE_HELP);
    }

    let whatsapp = null;
    const whatsappInput = value('reg-whatsapp');
    if (whatsappInput) {
      whatsapp = normalizePhone(whatsappInput);
      if (!whatsapp) fail('whatsapp_number', PHONE_HELP);
    }

    const email = value('reg-email');
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
      fail('email', 'Enter a valid email address, or leave it blank.');
    }

    const nationalId = value('reg-national-id');
    if (nationalId && !/^[A-Za-z0-9\- ]{4,32}$/.test(nationalId)) {
      fail('national_id', 'Enter a valid National ID, or leave it blank.');
    }

    const village = value('reg-village');
    if (village.length < 2) fail('village', 'Enter your village or town.');

    const districtId = value('reg-district');
    if (!districtId) fail('district_id', 'Select your district.');

    const business = value('reg-business-name');
    if (state.role !== 'farmer' && !business) {
      fail('business_name', 'Enter your business or organisation name.');
    }

    if (firstBad) {
      focusField(firstBad);
      return null;
    }

    // Show the applicant the canonical form we are about to store, so the number
    // in the review step is the number in the database.
    if (phone) byId('reg-phone').value = phone;
    if (whatsapp) byId('reg-whatsapp').value = whatsapp;

    return {
      full_name: fullName,
      phone_number: phone,
      whatsapp_number: whatsapp,
      email: email,
      national_id: nationalId,
      village: village,
      district_id: Number(districtId),
      business_name: business
    };
  }

  /* ── Duplicate preflight ────────────────────────────────────────────────── */

  // Best effort: tells the applicant they already have an application before they
  // fill in the rest of the form. A failure here never blocks progress, because
  // register.php performs the authoritative check when the form is submitted.
  async function preflight(details) {
    const query = new URLSearchParams({ action: 'preflight', phone_number: details.phone_number });
    if (details.whatsapp_number) query.set('whatsapp_number', details.whatsapp_number);
    if (details.email) query.set('email', details.email);
    if (details.national_id) query.set('national_id', details.national_id);

    try {
      const response = await fetch('register.php?' + query.toString(), { headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!data || !data.success || !Array.isArray(data.matches) || !data.matches.length) return true;

      const fieldFor = { phone: 'phone_number', whatsapp: 'whatsapp_number', email: 'email', national_id: 'national_id' };
      data.matches.forEach(match => {
        const field = fieldFor[match.field] || 'phone_number';
        setError(field, 'That ' + match.label + ' is already registered under application ' + match.ref +
          ' (' + match.status + '). Use "Check status" instead of registering again.');
      });
      focusField(fieldFor[data.matches[0].field] || 'phone_number');
      return false;
    } catch (error) {
      return true;
    }
  }

  /* ── Review ─────────────────────────────────────────────────────────────── */

  function buildReview() {
    const review = byId('reg-review');
    if (!review) return;
    const districtSelect = byId('reg-district');
    const districtName = districtSelect && districtSelect.selectedOptions[0] ? districtSelect.selectedOptions[0].textContent : '—';
    const crops = selectedCrops().map(crop => crop.name).join(', ');
    const role = state.role ? state.role.charAt(0).toUpperCase() + state.role.slice(1) : '—';

    const rows = [
      ['Role', role],
      ['Full name', value('reg-full-name')],
      ['Phone', value('reg-phone')],
      ['WhatsApp', value('reg-whatsapp') || 'Not provided'],
      ['Email', value('reg-email') || 'Not provided'],
      ['National ID', value('reg-national-id') || 'Not provided'],
      ['District', districtName],
      ['Village / town', value('reg-village')],
      ['Crops', crops || '—']
    ];
    if (state.role !== 'farmer') rows.push(['Business', value('reg-business-name') || '—']);

    // Applicant-supplied text goes in through textContent only.
    review.textContent = '';
    rows.forEach(([label, detail]) => {
      const row = document.createElement('div');
      const dt = document.createElement('dt');
      dt.textContent = label;
      const dd = document.createElement('dd');
      dd.textContent = detail || '—';
      row.append(dt, dd);
      review.appendChild(row);
    });
  }

  /* ── Wiring ─────────────────────────────────────────────────────────────── */

  qsa('.register-role').forEach(button => {
    button.addEventListener('click', () => {
      state.role = button.dataset.role || '';
      qsa('.register-role').forEach(other => {
        const selected = other === button;
        other.classList.toggle('selected', selected);
        other.setAttribute('aria-checked', selected ? 'true' : 'false');
      });
      const businessField = byId('business-field');
      if (businessField) businessField.hidden = state.role === 'farmer';
      setError('user_type', '');
      setStep(2);
    });
  });

  qsa('[data-back]').forEach(button => {
    button.addEventListener('click', () => setStep(state.step - 1));
  });

  qsa('[data-next]').forEach(button => {
    button.addEventListener('click', async () => {
      if (state.step === 2) {
        const details = validateDetails();
        if (!details) return;
        button.disabled = true;
        const label = button.textContent;
        button.textContent = 'Checking…';
        const ok = await preflight(details);
        button.disabled = false;
        button.textContent = label;
        if (!ok) return;
      }
      if (state.step === 3 && !selectedCrops().length) {
        setError('crops', 'Select at least one crop.');
        return;
      }
      setError('crops', '');
      setStep(state.step + 1);
    });
  });

  // Clear a field's error as soon as the user starts fixing it.
  form.addEventListener('input', (event) => {
    const name = event.target && event.target.name;
    if (name) setError(name, '');
    if (event.target && event.target.name === 'crop_ids') setError('crops', '');
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (state.submitting) return;

    if (!state.role) {
      setStep(1);
      setError('user_type', 'Choose whether you are registering as a farmer, seller or buyer.');
      return;
    }

    const details = validateDetails();
    if (!details) {
      setStep(2);
      return;
    }

    const crops = selectedCrops();
    if (!crops.length) {
      setStep(3);
      setError('crops', 'Select at least one crop.');
      return;
    }

    const button = byId('reg-submit');
    state.submitting = true;
    button.disabled = true;
    const originalLabel = button.textContent;
    button.textContent = 'Submitting…';
    setError('submit', '');

    try {
      const response = await fetch('register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          user_type: state.role,
          full_name: details.full_name,
          phone_number: details.phone_number,
          whatsapp_number: details.whatsapp_number || '',
          email: details.email,
          national_id: details.national_id,
          district_id: details.district_id,
          village: details.village,
          business_name: details.business_name,
          crop_ids: crops.map(crop => crop.id)
        })
      });
      const data = await response.json();

      if (!data.success) {
        // The server names the field it rejected; take the user back to it.
        const field = data.field || '';
        if (field === 'crops') {
          setStep(3);
          setError('crops', data.error);
        } else if (field === 'user_type') {
          setStep(1);
          setError('user_type', data.error);
        } else if (field) {
          setStep(2);
          setError(field, data.error);
          focusField(field);
        } else {
          setError('submit', data.error || 'Registration failed. Please try again.');
        }
        return;
      }

      qsa('#registration-form .register-step').forEach(el => { el.hidden = true; el.classList.remove('active'); });
      const progress = qs('.register-progress');
      if (progress) progress.hidden = true;
      byId('reg-reference').textContent = data.reference;
      const success = byId('register-success');
      success.hidden = false;
      success.scrollIntoView({ block: 'start', behavior: 'smooth' });
    } catch (error) {
      setError('submit', 'We could not reach the server. Check your connection and try again.');
    } finally {
      state.submitting = false;
      button.disabled = false;
      button.textContent = originalLabel;
    }
  });

  loadDistricts();
  loadCrops();
})();
