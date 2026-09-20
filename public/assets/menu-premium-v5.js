(()=>{
'use strict';
const d=document,$=(s,r=d)=>r.querySelector(s),$$=(s,r=d)=>[...r.querySelectorAll(s)];
d.documentElement.classList.add('js-enhanced');
const root=$('[data-menu-root]');if(!root)return;
const storageKey=root.dataset.storageKey||'eventmenu-menu';
const cartStateEl=$('#menu-cart-state');
const serverCart=(()=>{try{return JSON.parse(cartStateEl?.textContent||'[]')}catch{return[]}})();
const safeGet=k=>{try{return localStorage.getItem(k)}catch{return null}},safeSet=(k,v)=>{try{localStorage.setItem(k,v)}catch{}},safeRemove=k=>{try{localStorage.removeItem(k)}catch{}};
const money=cents=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format((Number(cents)||0)/100);
const toastRegion=$('.toast-region')||(()=>{const x=d.createElement('div');x.className='toast-region';x.setAttribute('aria-live','polite');d.body.append(x);return x})();
const toast=msg=>{const el=d.createElement('div');el.className='toast';el.textContent=msg;toastRegion.replaceChildren(el);setTimeout(()=>{if(el.isConnected)el.remove()},2600)};

const checkoutPending=safeGet(storageKey+':checkoutPending')==='1';
if(checkoutPending){if(serverCart.length===0)safeRemove(storageKey+':cart');safeRemove(storageKey+':checkoutPending')}
function persistServerCart(){if(serverCart.length){safeSet(storageKey+':cart',JSON.stringify({savedAt:Date.now(),lines:serverCart}))}else{const backup=safeGet(storageKey+':cart');if(backup)showRestore(backup)}}
function showRestore(raw){let parsed;try{parsed=JSON.parse(raw)}catch{return}if(!Array.isArray(parsed?.lines)||!parsed.lines.length)return;if(Date.now()-(Number(parsed.savedAt)||0)>1000*60*60*24*7){safeRemove(storageKey+':cart');return}const host=$('[data-restore-host]');if(!host)return;const box=d.createElement('div');box.className='restore-banner';box.innerHTML='<span><strong>Você tinha um pedido em andamento.</strong><br>Quer recuperar os itens salvos neste aparelho?</span>';const btn=d.createElement('button');btn.type='button';btn.textContent='Restaurar';btn.addEventListener('click',()=>{const f=$('#restore-cart-form');const field=f?.querySelector('[name="cart_backup"]');if(f&&field){field.value=JSON.stringify(parsed.lines);f.submit()}});box.append(btn);host.replaceChildren(box)}
persistServerCart();

const search=$('#menu-search'),searchWrap=search?.closest('.search'),clear=$('.search-clear');
const filter=()=>{const q=(search?.value||'').trim().toLocaleLowerCase('pt-BR');searchWrap?.classList.toggle('has-value',!!q);$$('[data-product]').forEach(card=>{card.hidden=!!q&&!String(card.dataset.search||'').includes(q)});$$('.catalog-section').forEach(section=>{section.hidden=!$$('[data-product]',section).some(c=>!c.hidden)})};
search?.addEventListener('input',filter);clear?.addEventListener('click',()=>{search.value='';filter();search.focus()});

const catLinks=$$('[data-category-link]'),sections=$$('.catalog-section');
if('IntersectionObserver'in window&&sections.length){const io=new IntersectionObserver(entries=>{const visible=entries.filter(e=>e.isIntersecting).sort((a,b)=>b.intersectionRatio-a.intersectionRatio)[0];if(!visible)return;catLinks.forEach(a=>a.classList.toggle('active',a.getAttribute('href')==='#'+visible.target.id));const active=catLinks.find(a=>a.classList.contains('active'));active?.scrollIntoView({behavior:'smooth',inline:'center',block:'nearest'})},{rootMargin:'-145px 0px -55% 0px',threshold:[0,.15,.5]});sections.forEach(s=>io.observe(s))}
catLinks.forEach(a=>a.addEventListener('click',()=>catLinks.forEach(x=>x.classList.toggle('active',x===a))));

/*
 * Mobile scroll lock: native <dialog> + body overflow:hidden leaves some iOS/WebView
 * versions stuck after closing. Freeze the page at its actual Y and always restore it.
 */
const dialog=$('#product-dialog');
let lockedScrollY=0,scrollLocked=false;
function lockPageScroll(){
    if(scrollLocked)return;
    lockedScrollY=Math.max(0,window.scrollY||d.documentElement.scrollTop||0);
    scrollLocked=true;
    d.body.classList.add('menu-modal-open');
    d.body.style.position='fixed';d.body.style.top=`-${lockedScrollY}px`;d.body.style.left='0';d.body.style.right='0';d.body.style.width='100%';
}
function unlockPageScroll(restore=true){
    if(!scrollLocked&&!d.body.classList.contains('menu-modal-open'))return;
    const y=lockedScrollY;
    d.body.classList.remove('menu-modal-open');
    for(const prop of['position','top','left','right','width'])d.body.style.removeProperty(prop);
    scrollLocked=false;lockedScrollY=0;
    if(restore)requestAnimationFrame(()=>window.scrollTo({top:y,left:0,behavior:'auto'}));
}
const closeDialog=()=>{if(dialog?.open)dialog.close();else unlockPageScroll()};
$('.dialog-close',dialog||d)?.addEventListener('click',closeDialog);
dialog?.addEventListener('click',e=>{if(e.target===dialog)closeDialog()});
dialog?.addEventListener('cancel',e=>{e.preventDefault();closeDialog()});
dialog?.addEventListener('close',()=>unlockPageScroll(true));
window.addEventListener('pageshow',()=>{if(!dialog?.open)unlockPageScroll(false)});
window.addEventListener('pagehide',()=>unlockPageScroll(false));

function openProduct(card){
    if(!dialog)return;const form=$('form[data-product-form]',card);if(!form)return;
    const title=$('h3',card)?.textContent?.trim()||'Produto',desc=$('.desc',card)?.textContent?.trim()||'',img=$('.photo',card),base=Number(form.dataset.basePrice||0);
    const body=$('.product-sheet-body',dialog),photo=$('.product-sheet-photo',dialog);if(!body)return;
    $('.sheet-title',dialog).textContent=title;$('.sheet-desc',dialog).textContent=desc;$('.sheet-desc',dialog).hidden=!desc;$('.sheet-price',dialog).textContent=money(base);
    if(img){photo.src=img.src;photo.alt=img.alt||title;photo.hidden=false}else{photo.hidden=true;photo.removeAttribute('src')}
    const old=$('.sheet-form',dialog);if(old)old.remove();
    const clone=form.cloneNode(true);clone.classList.add('sheet-form');clone.removeAttribute('data-product-form');
    const custom=$('.product-customizer',clone);if(custom){custom.hidden=false;custom.removeAttribute('class')}
    const originalQty=clone.querySelector('[name="qty"]');if(originalQty)originalQty.type='hidden';body.append(clone);
    const footerQty=$('.qty-stepper output',dialog),minus=$('[data-qty-minus]',dialog),plus=$('[data-qty-plus]',dialog),add=$('.sheet-add',dialog);let qty=Math.max(1,Number(originalQty?.value||1));
    const selectedDelta=()=>$$('input[data-price-delta]:checked',clone).reduce((sum,i)=>sum+Number(i.dataset.priceDelta||0),0);
    const update=()=>{if(originalQty)originalQty.value=String(qty);footerQty.textContent=String(qty);add.textContent='ADICIONAR • '+money((base+selectedDelta())*qty)};
    minus.onclick=()=>{qty=Math.max(1,qty-1);update()};plus.onclick=()=>{qty=Math.min(99,qty+1);update()};
    $$('input[data-price-delta]',clone).forEach(i=>i.addEventListener('change',()=>{const group=i.closest('[data-modifier-group]'),max=Number(group?.dataset.max||99);if(i.type==='checkbox'&&group){const checked=$$('input[type="checkbox"]:checked',group);if(checked.length>max){i.checked=false;toast(max===1?'Escolha apenas 1 opção.':`Escolha até ${max} opções.`)}}update()}));
    add.onclick=()=>{const invalid=$$('[data-modifier-group]',clone).find(g=>{const min=Number(g.dataset.min||0),max=Number(g.dataset.max||99),n=$$('input:checked',g).length;return n<min||n>max});if(invalid){invalid.scrollIntoView({behavior:'smooth',block:'center'});invalid.animate([{outline:'2px solid transparent'},{outline:'2px solid #b86d12'},{outline:'2px solid transparent'}],{duration:650});toast(invalid.dataset.rule||'Revise as opções obrigatórias.');return}add.disabled=true;add.textContent='Adicionando…';clone.submit()};
    update();
    try{dialog.showModal();lockPageScroll();requestAnimationFrame(()=>{body.scrollTop=0});setTimeout(()=>$('.dialog-close',dialog)?.focus({preventScroll:true}),30)}catch(err){unlockPageScroll(false);console.warn('Não foi possível abrir o produto.',err)}
}
$$('.open-product').forEach(btn=>btn.addEventListener('click',()=>openProduct(btn.closest('[data-product]'))));

const checkout=$('#checkout');
if(checkout){const steps=$$('.checkout-step',checkout),bars=$$('.checkout-progress span',checkout);let current=0;const show=i=>{current=Math.max(0,Math.min(steps.length-1,i));steps.forEach((s,n)=>s.hidden=n!==current);bars.forEach((b,n)=>{b.classList.toggle('active',n===current);b.classList.toggle('done',n<current)});steps[current]?.querySelector('input:not([type=hidden]),textarea,button')?.focus({preventScroll:true});checkout.scrollIntoView({behavior:'smooth',block:'nearest'})};const validStep=()=>{for(const el of $$('input,textarea,select',steps[current])){if(!el.checkValidity()){el.reportValidity();return false}}return true};$$('[data-step-next]',checkout).forEach(b=>b.addEventListener('click',()=>{if(validStep())show(current+1)}));$$('[data-step-back]',checkout).forEach(b=>b.addEventListener('click',()=>show(current-1)));checkout.addEventListener('submit',e=>{if(current<steps.length-1){e.preventDefault();if(validStep())show(current+1);return}safeSet(storageKey+':checkoutPending','1');const submit=$('button[type="submit"]',checkout);if(submit){submit.disabled=true;submit.textContent='Enviando pedido…'}});show(0);
const saved=(()=>{try{return JSON.parse(safeGet(storageKey+':customer')||'{}')}catch{return{}}})();for(const name of['name','phone','address']){const el=checkout.elements.namedItem(name);if(el&&saved[name]&&!el.value)el.value=saved[name];el?.addEventListener('input',()=>{const data={};for(const n of['name','phone','address']){const x=checkout.elements.namedItem(n);if(x)data[n]=x.value}safeSet(storageKey+':customer',JSON.stringify(data))})}}
const fulfillment=$$('input[name="fulfillment"]'),addressField=$('#address-field'),deliveryInfo=$('#delivery-info');function syncFulfillment(){const isDelivery=$('input[name="fulfillment"]:checked')?.value==='delivery';if(addressField){addressField.hidden=!isDelivery;const ta=$('textarea',addressField);if(ta)ta.required=!!isDelivery}if(deliveryInfo)deliveryInfo.hidden=!isDelivery}fulfillment.forEach(el=>el.addEventListener('change',syncFulfillment));syncFulfillment();
const offline=$('#offline-banner');function syncOnline(){if(!offline)return;offline.hidden=navigator.onLine;if(navigator.onLine&&offline.dataset.wasOffline==='1'){toast('Conexão recuperada ✓');offline.dataset.wasOffline='0'}else if(!navigator.onLine)offline.dataset.wasOffline='1'}addEventListener('online',syncOnline);addEventListener('offline',syncOnline);syncOnline();
$$('form[data-clear-form]').forEach(f=>f.addEventListener('submit',()=>{safeRemove(storageKey+':cart');const b=f.querySelector('button');if(b)b.disabled=true}));$$('form[data-remove-form]').forEach(f=>f.addEventListener('submit',()=>{if(serverCart.length<=1)safeRemove(storageKey+':cart');const b=f.querySelector('button');if(b)b.disabled=true}));
})();
