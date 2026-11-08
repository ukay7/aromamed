(() => {
    const password=document.getElementById('login-password');
    const toggle=document.getElementById('password-toggle');
    if(!password||!toggle)return;
    toggle.addEventListener('click',()=>{const visible=password.type==='password';password.type=visible?'text':'password';toggle.textContent=visible?'Hide':'Show';toggle.setAttribute('aria-label',visible?'Hide password':'Show password');toggle.setAttribute('aria-pressed',String(visible));});
    password.addEventListener('keyup',event=>{document.getElementById('caps-lock-note').hidden=!event.getModifierState('CapsLock');});
})();
