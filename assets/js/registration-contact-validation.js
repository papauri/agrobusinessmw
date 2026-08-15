/* AgroBusiness Malawi — registration contact validation. */
(function () {
    'use strict';
    const get = id => document.getElementById(id);
    const phoneApi = () => window.AgroPhone;

    function addWhatsAppField() {
        if (get('reg-whatsapp')) return;
        const phone = get('reg-phone');
        if (!phone || !phone.parentElement) return;
        const group = document.createElement('div');
        group.className = 'form-group reg-whatsapp-group';
        group.innerHTML = `<label for="reg-whatsapp">WhatsApp Number <span class="optional-label">(optional)</span></label><input type="tel" id="reg-whatsapp" placeholder="0888 123 456 or +265 888 123 456" autocomplete="tel" inputmode="tel" maxlength="20" aria-describedby="reg-whatsapp-help"><small id="reg-whatsapp-help" class="form-help">Use the number connected to WhatsApp. Malawi local numbers are converted automatically.</small>`;
        phone.parentElement.insertAdjacentElement('afterend', group);
    }
    function clearError(field){if(!field)return;field.classList.remove('is-invalid');const e=field.parentNode?.querySelector('.registration-contact-error');if(e)e.remove();}
    function error(field,msg){if(!field)return;clearError(field);field.classList.add('is-invalid');const e=document.createElement('span');e.className='field-error registration-contact-error';e.textContent=msg;field.parentNode.appendChild(e);}
    function normalize(id,required,label){const f=get(id);if(!f)return{value:'',valid:!required};const raw=f.value.trim();clearError(f);if(!raw){if(required){error(f,`${label} is required.`);return{value:'',valid:false};}return{value:'',valid:true};}const n=phoneApi()?.normalize?.(raw);if(!n){error(f,`${label} is not valid. Try 0888 123 456 or +265 888 123 456.`);return{value:'',valid:false};}f.value=n;return{value:n,valid:true};}
    function contacts(){const phone=normalize('reg-phone',true,'Phone number');const whatsapp=normalize('reg-whatsapp',false,'WhatsApp number');return{phone,whatsapp,valid:phone.valid&&whatsapp.valid};}
    async function checkDuplicates(phone,whatsapp,email,nationalId,fullName){const q=new URLSearchParams({phone,whatsapp_number:whatsapp,email,national_id:nationalId,full_name:fullName});const r=await fetch('registration-check.php?'+q.toString());if(!r.ok)throw new Error(`HTTP ${r.status}`);return r.json();}

    async function submit(){
        const app=window.app;if(!app)return;const c=contacts();if(!c.valid)return;
        const state=app._regState, submitBtn=get('reg-submit-btn');if(!submitBtn)return;
        const fullName=get('reg-full-name').value.trim(),email=get('reg-email').value.trim(),nationalId=get('reg-national-id').value.trim(),districtId=get('reg-district').value,village=get('reg-village').value.trim();
        const crops=[...document.querySelectorAll('#reg-crops-grid input:checked')].map(el=>el.dataset.name).join(', '),business=get('reg-business-name')?.value.trim()||'';
        submitBtn.disabled=true;submitBtn.textContent='Submitting…';
        try{
            const r=await fetch('api.php?action=submit_application',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user_type:state.userType,full_name:fullName,phone_number:c.phone.value,email,national_id:nationalId,district_id:districtId?parseInt(districtId,10):null,village,crops_of_interest:crops,business_name:business,channel:'web'})});
            const data=await r.json();if(!data.success)throw new Error(data.error||'Submission failed.');
            const cr=await fetch('registration-contact.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({application_ref:data.ref,phone_number:c.phone.value,whatsapp_number:c.whatsapp.value||null})});
            const cd=await cr.json();if(!cd.success)throw new Error(cd.error||'Contact details could not be saved.');
            document.querySelectorAll('.reg-step-content').forEach(el=>el.style.display='none');get('reg-step-success').style.display='';get('reg-ref-number').textContent=data.ref;document.querySelectorAll('.reg-steps .reg-step').forEach(el=>{el.classList.remove('reg-step-active');el.classList.add('reg-step-done');});
        }catch(e){console.error('Registration failed:',e);app.showNotification(e.message||'Registration failed. Please try again.','error');submitBtn.disabled=false;submitBtn.textContent='Submit Application ✓';}
    }

    function install(){
        addWhatsAppField();
        document.addEventListener('click',async function(event){
            const target=event.target.closest?.('#reg-step2-next');if(!target)return;const modal=get('register-modal');if(!modal)return;addWhatsAppField();event.preventDefault();event.stopImmediatePropagation();
            const name=get('reg-full-name').value.trim(),email=get('reg-email').value.trim(),nationalId=get('reg-national-id').value.trim(),district=get('reg-district').value,village=get('reg-village').value.trim(),c=contacts();
            if(name.length<2)error(get('reg-full-name'),'Full name is required (at least 2 characters).');
            if(email&&!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email))error(get('reg-email'),'Enter a valid email address or leave blank.');
            if(village.length<2)error(get('reg-village'),'Village or town is required.');
            if(!district)error(get('reg-district'),'Please select your district.');
            const textValid=name.length>=2&&(!email||/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email))&&village.length>=2&&!!district;if(!c.valid||!textValid)return;
            target.disabled=true;const old=target.textContent;target.textContent='Checking…';
            try{const result=await checkDuplicates(c.phone.value,c.whatsapp.value,email,nationalId,name);if(!result.success)throw new Error(result.error||'Could not validate registration.');const matches=result.matches||[],hard=matches.filter(m=>m.hard);if(hard.length){hard.forEach(m=>{const id=m.field==='whatsapp'?'reg-whatsapp':m.field==='national_id'?'reg-national-id':m.field==='name'?'reg-full-name':'reg-'+m.field;error(get(id),`This ${m.field==='whatsapp'?'WhatsApp number':m.field} is already registered (Ref ${m.ref} · ${m.status}).`);});return;}const soft=matches.filter(m=>!m.hard);if(soft.length&&!window.app._regNameWarned){window.app._regNameWarned=true;error(get('reg-full-name'),'Someone with this name has already applied. Press Next again if this is a different person.');return;}window.app._regGotoStep(3);}catch(e){app.showNotification(e.message||'Could not validate your contact details.','error');}finally{target.disabled=false;target.textContent=old;}
        },true);
        document.addEventListener('click',function(event){const target=event.target.closest?.('#reg-submit-btn');if(!target)return;const modal=get('register-modal');if(!modal)return;addWhatsAppField();event.preventDefault();event.stopImmediatePropagation();submit();},true);
        ['reg-phone','reg-whatsapp'].forEach(id=>{const f=get(id);if(f)f.addEventListener('input',()=>clearError(f));});
        if(window.app&&typeof window.app.openRegistrationModal==='function'){const original=window.app.openRegistrationModal.bind(window.app);window.app.openRegistrationModal=function(){original();addWhatsAppField();const f=get('reg-whatsapp');if(f){f.value='';clearError(f);}};}
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);else install();
})();
