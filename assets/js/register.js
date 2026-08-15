(function () {
  'use strict';

  const state = { step: 1, role: '', districts: [] };
  const qs = (s) => document.querySelector(s);
  const qsa = (s) => Array.from(document.querySelectorAll(s));
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function normalize(value) {
    if (window.AgroPhone && typeof window.AgroPhone.normalize === 'function') return window.AgroPhone.normalize(value, '265');
    let v = String(value || '').trim().replace(/[\s().-]+/g, '');
    if (!v) return null;
    if (v.startsWith('00')) v = '+' + v.slice(2);
    else if (/^0[0-9]{9}$/.test(v)) v = '+265' + v.slice(1);
    else if (/^[1-9][0-9]{8}$/.test(v)) v = '+265' + v;
    return /^\+[1-9][0-9]{7,14}$/.test(v) ? v : null;
  }

  function setError(id, message) {
    const el = document.getElementById(id);
    if (el) el.textContent = message || '';
  }

  function setStep(step) {
    state.step = step;
    qsa('.register-step').forEach(el => { el.hidden = Number(el.dataset.step) !== step; el.classList.toggle('active', Number(el.dataset.step) === step); });
    qsa('[data-progress]').forEach(el => el.classList.toggle('active', Number(el.dataset.progress) <= step));
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (step === 4) buildReview();
  }

  async function getJson(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Request failed');
    return data;
  }

  async function loadDistricts() {
    const select = qs('#reg-district');
    try {
      const data = await getJson('api.php?action=districts');
      const rows = data.data || data.districts || [];
      state.districts = rows;
      select.innerHTML = '<option value="">— Select district —</option>' + rows.map(d => `<option value="${Number(d.id)}">${esc(d.name)}</option>`).join('');
    } catch (e) {
      select.innerHTML = '<option value="">Unable to load districts</option>';
      setError('contact-error', 'Districts could not be loaded. Please refresh and try again.');
    }
  }

  async function loadCrops() {
    const box = qs('#reg-crops');
    try {
      const data = await getJson('api.php?action=crops');
      const rows = data.data || data.crops || [];
      box.innerHTML = rows.map(c => `<label class="register-crop"><input type="checkbox" value="${Number(c.id)}" data-name="${esc(c.name)}"><span>${esc(c.name)}</span></label>`).join('');
    } catch (e) {
      box.textContent = 'Crops could not be loaded. Please refresh and try again.';
    }
  }

  function validateContact() {
    setError('contact-error', '');
    const name = qs('#reg-full-name').value.trim();
    const phoneInput = qs('#reg-phone').value.trim();
    const whatsappInput = qs('#reg-whatsapp').value.trim();
    const email = qs('#reg-email').value.trim();
    const village = qs('#reg-village').value.trim();
    const district = qs('#reg-district').value;
    const business = qs('#reg-business-name').value.trim();

    if (name.length < 2) return 'Enter your full name.';
    const phone = normalize(phoneInput);
    if (!phone) return 'Enter a valid phone number. Example: 0888 123 456 or +447700900123.';
    const whatsapp = normalize(whatsappInput);
    if (whatsappInput && !whatsapp) return 'Enter a valid WhatsApp number or leave it blank.';
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return 'Enter a valid email address.';
    if (village.length < 2) return 'Enter your village or town.';
    if (!district) return 'Select your district.';
    if (state.role !== 'farmer' && !business) return 'Enter your business or organisation name.';
    return null;
  }

  function buildReview() {
    const district = qs('#reg-district').selectedOptions[0]?.text || '—';
    const crops = qsa('#reg-crops input:checked').map(i => i.dataset.name);
    const phone = normalize(qs('#reg-phone').value);
    const whatsapp = normalize(qs('#reg-whatsapp').value);
    const role = state.role.charAt(0).toUpperCase() + state.role.slice(1);
    qs('#reg-review').innerHTML = `
      <div><span>Role</span><strong>${esc(role)}</strong></div>
      <div><span>Full name</span><strong>${esc(qs('#reg-full-name').value.trim())}</strong></div>
      <div><span>Phone</span><strong>${esc(phone || '—')}</strong></div>
      <div><span>WhatsApp</span><strong>${esc(whatsapp || '—')}</strong></div>
      <div><span>Email</span><strong>${esc(qs('#reg-email').value.trim() || '—')}</strong></div>
      <div><span>District</span><strong>${esc(district)}</strong></div>
      <div><span>Village / town</span><strong>${esc(qs('#reg-village').value.trim())}</strong></div>
      <div><span>Business</span><strong>${esc(qs('#reg-business-name').value.trim() || '—')}</strong></div>
      <div><span>Crops</span><strong>${esc(crops.join(', ') || '—')}</strong></div>`;
  }

  qsa('.register-role').forEach(btn => btn.addEventListener('click', () => {
    state.role = btn.dataset.role;
    qsa('.register-role').forEach(b => b.classList.toggle('selected', b === btn));
    qs('#business-wrap').style.display = state.role === 'farmer' ? 'none' : '';
    setError('role-error', '');
    setTimeout(() => setStep(2), 120);
  }));

  qsa('[data-back]').forEach(btn => btn.addEventListener('click', () => setStep(Math.max(1, state.step - 1)));
  qsa('[data-next]').forEach(btn => btn.addEventListener('click', () => {
    if (state.step === 2) {
      const error = validateContact();
      if (error) { setError('contact-error', error); return; }
    }
    if (state.step === 3 && !qsa('#reg-crops input:checked').length) { setError('crop-error', 'Select at least one crop.'); return; }
    setStep(state.step + 1);
  }));

  qs('#registration-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('submit-error', '');
    const contactError = validateContact();
    if (contactError) { setStep(2); setError('contact-error', contactError); return; }
    const crops = qsa('#reg-crops input:checked').map(i => i.dataset.name);
    if (!crops.length) { setStep(3); setError('crop-error', 'Select at least one crop.'); return; }

    const button = qs('#reg-submit');
    button.disabled = true;
    button.textContent = 'Submitting…';
    const payload = {
      user_type: state.role,
      full_name: qs('#reg-full-name').value.trim(),
      phone_number: normalize(qs('#reg-phone').value),
      whatsapp_number: normalize(qs('#reg-whatsapp').value) || '',
      email: qs('#reg-email').value.trim(),
      national_id: qs('#reg-national-id').value.trim(),
      district_id: Number(qs('#reg-district').value),
      village: qs('#reg-village').value.trim(),
      crops_of_interest: crops.join(', '),
      business_name: qs('#reg-business-name').value.trim()
    };

    try {
      const res = await fetch('register.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload) });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Registration failed.');
      qsa('#registration-form .register-step').forEach(el => { el.hidden = true; });
      qs('.register-progress').hidden = true;
      qs('#reg-reference').textContent = data.reference;
      qs('#register-success').hidden = false;
    } catch (e) {
      setError('submit-error', e.message || 'Registration failed. Please try again.');
      button.disabled = false;
      button.textContent = 'Submit application';
    }
  });

  loadDistricts();
  loadCrops();
})();
