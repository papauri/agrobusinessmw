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

  /* ── Language ───────────────────────────────────────────────────────────── */

  // The page renders English, then this table re-labels it for the reader's
  // stored language (assets/js/i18n.js reads the same `preferredLanguage` key
  // app.js writes). Keys match the data-i18n attributes in register.php.
  //
  // These strings are for instant feedback only. register.php re-validates
  // everything and returns its own localised message, which wins.
  const copy = {
    en: {
      pageTitle: 'Register',
      home: 'Home',
      heading: 'Join the agricultural community',
      intro: 'Register as a farmer, seller or buyer. Only your phone number is required — WhatsApp, email and National ID are optional.',
      stepRole: 'Role', stepDetails: 'Details', stepCrops: 'Crops', stepReview: 'Review',
      roleHeading: 'How will you use AgroBusiness?',
      roleFarmer: 'Farmer', roleFarmerDesc: 'Grow and manage crops',
      roleSeller: 'Seller', roleSellerDesc: 'Sell agricultural products',
      roleBuyer: 'Buyer', roleBuyerDesc: 'Source agricultural products',
      alreadyApplied: 'Already applied?', checkStatusLink: 'Check your application status',
      detailsHeading: 'Your contact details',
      fieldFullName: 'Full name', fieldPhone: 'Phone number', fieldWhatsapp: 'WhatsApp number',
      fieldEmail: 'Email', fieldNationalId: 'National ID', fieldVillage: 'Village / town',
      fieldDistrict: 'District', fieldBusiness: 'Business / organisation name',
      optional: '(optional)',
      phoneHint: 'Malawi numbers are saved as +265…. For any other country, include the country code, e.g. +44 7700 900123.',
      whatsappHint: 'Leave blank if you do not use WhatsApp.',
      whatsappPlaceholder: 'Same format as phone',
      loadingDistricts: 'Loading districts…', loadingCrops: 'Loading crops…',
      selectDistrict: '— Select district —',
      cropsHeading: 'What do you grow or trade?', cropsHelp: 'Select at least one crop.',
      reviewHeading: 'Review your registration',
      kycNote: 'By submitting you agree to KYC verification. We will review your application and give you a reference number.',
      back: 'Back', continue: 'Continue', review: 'Review', submit: 'Submit application',
      submitting: 'Submitting…', checking: 'Checking…',
      successHeading: 'Application submitted', successRefLabel: 'Your reference number is',
      successKeep: 'Keep this number safe — you need it to check your application status.',
      checkStatus: 'Check status', backHome: 'Back to home',
      // Review labels
      revRole: 'Role', revName: 'Full name', revPhone: 'Phone', revWhatsapp: 'WhatsApp',
      revEmail: 'Email', revNationalId: 'National ID', revDistrict: 'District',
      revVillage: 'Village / town', revCrops: 'Crops', revBusiness: 'Business',
      notProvided: 'Not provided',
      // Validation
      errName: 'Enter your full name.', errNameLong: 'That name is too long.',
      errPhoneRequired: 'A phone number is required.',
      errPhone: 'Enter a Malawi number as 0888 123 456, or an international number with its country code, e.g. +44 7700 900123.',
      errEmail: 'Enter a valid email address, or leave it blank.',
      errNationalId: 'Enter a valid National ID, or leave it blank.',
      errVillage: 'Enter your village or town.', errDistrict: 'Select your district.',
      errBusiness: 'Enter your business or organisation name.',
      errCrops: 'Select at least one crop.',
      errRole: 'Choose whether you are registering as a farmer, seller or buyer.',
      errDistrictsLoad: 'Districts could not be loaded. Check your connection and reload the page.',
      errDistrictsOption: 'Districts could not be loaded',
      errCropsLoad: 'Crops could not be loaded. Check your connection and reload the page.',
      errNoCrops: 'No crops are available yet. Please try again later.',
      errNetwork: 'We could not reach the server. Check your connection and try again.',
      errGeneric: 'Registration failed. Please try again.',
      errPhoneTooling: 'Phone number checking is unavailable. Please reload the page.',
      errDuplicate: 'That {label} is already registered under application {ref} ({status}). Use "Check status" instead of registering again.'
    },
    ci: {
      pageTitle: 'Lembetsani',
      home: 'Kunyumba',
      heading: "Lowani m'gulu la alimi",
      intro: 'Lembetsani ngati mlimi, wogulitsa kapena wogula. Nambala ya foni yokha ndi yofunika — WhatsApp, imelo ndi chiphaso cha dziko ndi zosankha.',
      stepRole: 'Udindo', stepDetails: 'Zambiri', stepCrops: 'Mbewu', stepReview: 'Onaninso',
      roleHeading: 'Mudzagwiritsa ntchito AgroBusiness bwanji?',
      roleFarmer: 'Mlimi', roleFarmerDesc: 'Kulima ndi kusamalira mbewu',
      roleSeller: 'Wogulitsa', roleSellerDesc: 'Kugulitsa zinthu za ulimi',
      roleBuyer: 'Wogula', roleBuyerDesc: 'Kugula zinthu za ulimi',
      alreadyApplied: 'Munalembetsapo kale?', checkStatusLink: 'Onani mmene fomu yanu ikuyendera',
      detailsHeading: 'Zambiri zolumikizirana nanu',
      fieldFullName: 'Dzina lanu lonse', fieldPhone: 'Nambala ya foni', fieldWhatsapp: 'Nambala ya WhatsApp',
      fieldEmail: 'Imelo', fieldNationalId: 'Chiphaso cha dziko', fieldVillage: 'Mudzi / tauni',
      fieldDistrict: 'Chigawo', fieldBusiness: 'Dzina la bizinesi kapena bungwe',
      optional: '(sikofunikira)',
      phoneHint: 'Nambala za ku Malawi zimasungidwa ngati +265…. Ku mayiko ena, lembani nambala ya dziko, mwachitsanzo +44 7700 900123.',
      whatsappHint: 'Musalembe kalikonse ngati simugwiritsa ntchito WhatsApp.',
      whatsappPlaceholder: 'Motere ngati nambala ya foni',
      loadingDistricts: 'Kutsitsa zigawo…', loadingCrops: 'Kutsitsa mbewu…',
      selectDistrict: '— Sankhani chigawo —',
      cropsHeading: 'Mumalima kapena kugulitsa chiyani?', cropsHelp: 'Sankhani mbewu imodzi kapena kupitirira.',
      reviewHeading: 'Onaninso zomwe mwalemba',
      kycNote: 'Mukatumiza, mukuvomereza kutsimikiziridwa kwa KYC. Tidzayang\'ana fomu yanu ndipo tidzakupatsani nambala yodziwikira.',
      back: 'Bwererani', continue: 'Pitirizani', review: 'Onaninso', submit: 'Tumizani fomu',
      submitting: 'Kutumiza…', checking: 'Kufufuza…',
      successHeading: 'Fomu yanu yatumizidwa', successRefLabel: 'Nambala yanu yodziwikira ndi',
      successKeep: 'Sungani nambala imeneyi — mudzaifuna kuti muone mmene fomu yanu ikuyendera.',
      checkStatus: 'Onani mkhalidwe', backHome: 'Bwererani kunyumba',
      revRole: 'Udindo', revName: 'Dzina lonse', revPhone: 'Foni', revWhatsapp: 'WhatsApp',
      revEmail: 'Imelo', revNationalId: 'Chiphaso cha dziko', revDistrict: 'Chigawo',
      revVillage: 'Mudzi / tauni', revCrops: 'Mbewu', revBusiness: 'Bizinesi',
      notProvided: 'Sanaperekedwe',
      errName: 'Lembani dzina lanu lonse.', errNameLong: 'Dzinali ndi lalitali kwambiri.',
      errPhoneRequired: 'Nambala ya foni ndi yofunika.',
      errPhone: 'Lembani nambala ya ku Malawi motere 0888 123 456, kapena nambala ya kunja ndi nambala ya dziko, mwachitsanzo +44 7700 900123.',
      errEmail: 'Lembani imelo yolondola, kapena musalembe kalikonse.',
      errNationalId: 'Lembani chiphaso cha dziko cholondola, kapena musalembe kalikonse.',
      errVillage: 'Lembani mudzi kapena tauni yanu.', errDistrict: 'Sankhani chigawo chanu.',
      errBusiness: 'Lembani dzina la bizinesi kapena bungwe lanu.',
      errCrops: 'Sankhani mbewu imodzi kapena kupitirira.',
      errRole: 'Sankhani ngati mukulembetsa ngati mlimi, wogulitsa kapena wogula.',
      errDistrictsLoad: 'Zigawo sizinatsitsidwe. Onani intaneti yanu ndipo tsegulaninso tsambali.',
      errDistrictsOption: 'Zigawo sizinatsitsidwe',
      errCropsLoad: 'Mbewu sizinatsitsidwe. Onani intaneti yanu ndipo tsegulaninso tsambali.',
      errNoCrops: 'Palibe mbewu zomwe zilipo pakadali pano. Yesaninso pambuyo pake.',
      errNetwork: 'Sitinathe kulumikizana ndi seva. Onani intaneti yanu ndipo yesaninso.',
      errGeneric: 'Kulembetsa kwalephera. Yesaninso.',
      errPhoneTooling: 'Kuyang\'ana nambala ya foni sikukugwira ntchito. Tsegulaninso tsambali.',
      errDuplicate: 'Zambiri izi zalembetsedwa kale pa fomu {ref} ({status}): {label}. Gwiritsani ntchito "Onani mkhalidwe" m\'malo molembetsanso.'
    }
  };

  const lang = () => (window.AgroLang ? window.AgroLang.current() : 'en');
  const t = (key, vars) => {
    let text = (copy[lang()] && copy[lang()][key]) || copy.en[key] || key;
    if (vars) Object.keys(vars).forEach(k => { text = text.replace('{' + k + '}', vars[k]); });
    return text;
  };

  /* Re-label every [data-i18n] element and placeholder. Called on load and
     whenever the language changes, so switching never reloads the page and
     never loses what the user has typed. */
  function applyLanguage() {
    qsa('[data-i18n]').forEach(el => {
      const value = t(el.dataset.i18n);
      if (value) el.textContent = value;
    });
    qsa('[data-i18n-placeholder]').forEach(el => {
      const value = t(el.dataset.i18nPlaceholder);
      if (value) el.placeholder = value;
    });

    document.title = t('pageTitle') + ' — AgroBusiness Malawi';

    const flag = byId('register-lang-flag');
    const code = byId('register-lang-code');
    if (flag) flag.textContent = lang() === 'ci' ? '🇲🇼' : '🇬🇧';
    if (code) code.textContent = lang() === 'ci' ? 'CI' : 'EN';

    // Rebuild the parts that were rendered from data rather than from markup.
    relabelDistrictPlaceholder();
    if (state.step === LAST_STEP) buildReview();
  }

  function relabelDistrictPlaceholder() {
    const select = byId('reg-district');
    if (!select || !select.options.length || !state.districts.length) return;
    if (select.options[0].value === '') select.options[0].textContent = t('selectDistrict');
  }

  /* ── Phone handling ─────────────────────────────────────────────────────── */

  // phone-normalizer.js is loaded synchronously before this file, so AgroPhone is
  // always present. Rather than carry a second, subtly different copy of the
  // rules as a fallback, fail loudly — a divergent normaliser writes wrong
  // numbers into the database, which is worse than not submitting at all.
  function normalizePhone(value) {
    if (!window.AgroPhone || typeof window.AgroPhone.normalize !== 'function') {
      throw new Error(t('errPhoneTooling'));
    }
    return window.AgroPhone.normalize(value);
  }


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
    if (select.options.length === 1 && select.options[0].value === '') {
      select.options[0].textContent = t('loadingDistricts');
    }
    try {
      const data = await getJson('api.php?action=districts');
      state.districts = data.data || [];
      // Built with DOM nodes, not markup: district names are database values and
      // textContent cannot be talked into becoming an element.
      select.textContent = '';
      const placeholder = new Option(t('selectDistrict'), '');
      select.appendChild(placeholder);
      state.districts.forEach(district => {
        select.appendChild(new Option(String(district.name), String(district.id)));
      });
    } catch (error) {
      select.textContent = '';
      select.appendChild(new Option(t('errDistrictsOption'), ''));
      setError('district_id', t('errDistrictsLoad'));
    }
  }

  async function loadCrops() {
    const box = byId('reg-crops');
    if (!box) return;
    if (!box.querySelector('input')) box.textContent = t('loadingCrops');
    try {
      const data = await getJson('api.php?action=crops');
      state.crops = data.data || [];
      box.textContent = '';
      if (!state.crops.length) {
        box.textContent = t('errNoCrops');
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
      box.textContent = t('errCropsLoad');
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
    if (fullName.length < 2) fail('full_name', t('errName'));
    else if (fullName.length > 150) fail('full_name', t('errNameLong'));

    let phone = null;
    const phoneInput = value('reg-phone');
    if (!phoneInput) fail('phone_number', t('errPhoneRequired'));
    else {
      phone = normalizePhone(phoneInput);
      if (!phone) fail('phone_number', t('errPhone'));
    }

    let whatsapp = null;
    const whatsappInput = value('reg-whatsapp');
    if (whatsappInput) {
      whatsapp = normalizePhone(whatsappInput);
      if (!whatsapp) fail('whatsapp_number', t('errPhone'));
    }

    const email = value('reg-email');
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
      fail('email', t('errEmail'));
    }

    const nationalId = value('reg-national-id');
    if (nationalId && !/^[A-Za-z0-9\- ]{4,32}$/.test(nationalId)) {
      fail('national_id', t('errNationalId'));
    }

    const village = value('reg-village');
    if (village.length < 2) fail('village', t('errVillage'));

    const districtId = value('reg-district');
    if (!districtId) fail('district_id', t('errDistrict'));

    const business = value('reg-business-name');
    if (state.role !== 'farmer' && !business) {
      fail('business_name', t('errBusiness'));
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
    const query = new URLSearchParams({ action: 'preflight', lang: lang(), phone_number: details.phone_number });
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
        // label and status_label are already in the reader's language: the
        // preflight request carries `lang`, so the server localised them.
        setError(field, t('errDuplicate', {
          label: match.label,
          ref: match.ref,
          status: match.status_label || match.status
        }));
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
    const roleKeys = { farmer: 'roleFarmer', seller: 'roleSeller', buyer: 'roleBuyer' };
    const role = state.role ? t(roleKeys[state.role]) : '—';

    const rows = [
      [t('revRole'), role],
      [t('revName'), value('reg-full-name')],
      [t('revPhone'), value('reg-phone')],
      [t('revWhatsapp'), value('reg-whatsapp') || t('notProvided')],
      [t('revEmail'), value('reg-email') || t('notProvided')],
      [t('revNationalId'), value('reg-national-id') || t('notProvided')],
      [t('revDistrict'), districtName],
      [t('revVillage'), value('reg-village')],
      [t('revCrops'), crops || '—']
    ];
    if (state.role !== 'farmer') rows.push([t('revBusiness'), value('reg-business-name') || '—']);

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
        button.textContent = t('checking');
        const ok = await preflight(details);
        button.disabled = false;
        button.textContent = label;
        if (!ok) return;
      }
      if (state.step === 3 && !selectedCrops().length) {
        setError('crops', t('errCrops'));
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
      setError('user_type', t('errRole'));
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
      setError('crops', t('errCrops'));
      return;
    }

    const button = byId('reg-submit');
    state.submitting = true;
    button.disabled = true;
    const originalLabel = button.textContent;
    button.textContent = t('submitting');
    setError('submit', '');

    try {
      const response = await fetch('register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          lang: lang(),
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
          setError('submit', data.error || t('errGeneric'));
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
      setError('submit', t('errNetwork'));
    } finally {
      state.submitting = false;
      button.disabled = false;
      button.textContent = originalLabel;
    }
  });

  /* ── Language wiring ────────────────────────────────────────────────────── */

  const langToggle = byId('register-lang-toggle');
  if (langToggle && window.AgroLang) {
    langToggle.addEventListener('click', () => window.AgroLang.toggle());
  }
  if (window.AgroLang) {
    // Re-label in place rather than reloading: the form may already be half
    // filled in, and a reload would throw that away.
    window.AgroLang.onChange(applyLanguage);
  }

  applyLanguage();
  loadDistricts();
  loadCrops();
})();
