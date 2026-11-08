(() => {
    'use strict';
    const colors = ['primary','sidebar','background'];
    const valid = value => /^#[a-f0-9]{6}$/i.test(value);
    function apply(theme) {
        if (!theme || !colors.every(key=>valid(theme[key]))) return;
        const root=document.documentElement.style;
        root.setProperty('--sage',theme.primary);
        root.setProperty('--branch-sidebar',theme.sidebar);
        root.setProperty('--paper',theme.background);
    }
    function mount(form,data,boot) {
        const section=document.createElement('section');section.className='theme-section';
        section.innerHTML='<h2 class="section-label">Branch color scheme</h2><p class="muted">Applies to this branch’s workspace and customer booking pages. Other branches keep their own colors.</p><label for="theme-key">Color scheme</label><select class="form-control" id="theme-key" name="theme_key"></select><div class="theme-preview" aria-label="Color scheme preview"><div class="theme-preview-aside">Your branch</div><div class="theme-preview-main"><strong>A beautiful day to care.</strong><span>New appointment</span></div></div><div class="form-grid theme-custom" hidden></div>';
        const select=section.querySelector('select');
        Object.entries(boot.themes).forEach(([key,theme])=>select.add(new Option(theme.label,key)));
        select.add(new Option('Custom colors','custom'));
        select.value=data.theme_key||'sage';
        const custom=section.querySelector('.theme-custom');
        colors.forEach(key=>{
            const label=document.createElement('label');label.textContent=key[0].toUpperCase()+key.slice(1)+' color';
            const input=document.createElement('input');input.type='color';input.className='form-control';input.name='theme_'+key;
            input.value=valid(data['theme_'+key]||'')?data['theme_'+key]:boot.theme[key];label.append(input);custom.append(label);
        });
        const hint=document.createElement('p');hint.className='form-hint';hint.textContent='Custom schemes need dark primary/sidebar colors and a light page background for readable text. Save settings to apply the scheme.';section.append(hint);
        function preview(){
            custom.hidden=select.value!=='custom';
            const palette=select.value==='custom'?Object.fromEntries(colors.map(key=>[key,custom.querySelector('[name=theme_'+key+']').value])):boot.themes[select.value];
            section.querySelector('.theme-preview-aside').style.backgroundColor=palette.sidebar;
            section.querySelector('.theme-preview-main').style.backgroundColor=palette.background;
            section.querySelector('.theme-preview-main span').style.backgroundColor=palette.primary;
        }
        select.addEventListener('change',preview);custom.addEventListener('input',preview);
        form.querySelector('.form-actions').before(section);preview();
    }
    window.ClinicTheme={apply,mount};
})();
