(()=>{'use strict';
const d=document;
const root=d.querySelector('[data-order-page]');if(!root)return;

/* DELYVRE consumer context. Direct/public EventMenu order pages remain unchanged. */
const params=new URLSearchParams(location.search);
const orderToken=params.get('t')||'';
const menuContextKey='delyvre:menu-context:v1';
const orderContextKey='delyvre:order-context:v1';
const sessionGet=key=>{try{return sessionStorage.getItem(key)}catch{return null}};
const sessionSet=(key,value)=>{try{sessionStorage.setItem(key,value)}catch{}};
const sessionRemove=key=>{try{sessionStorage.removeItem(key)}catch{}};
const readJson=raw=>{try{return JSON.parse(raw||'null')}catch{return null}};
const now=Date.now();
let delyvreContext=false;
let referrer=null;
try{referrer=d.referrer?new URL(d.referrer):null}catch{}
const sameOrigin=!!referrer&&referrer.origin===location.origin;
const fromMenu=sameOrigin&&/\/menu\.php$/i.test(referrer.pathname);
const fromOrder=sameOrigin&&/\/pedido\.php$/i.test(referrer.pathname);
const navType=performance.getEntriesByType?.('navigation')?.[0]?.type||'';
const continuing=fromOrder||navType==='reload'||navType==='back_forward';
const menuContext=readJson(sessionGet(menuContextKey));
const storedOrder=readJson(sessionGet(orderContextKey));

if(orderToken&&fromMenu&&menuContext&&Number(menuContext.expiresAt)>now){
  delyvreContext=true;
  sessionSet(orderContextKey,JSON.stringify({token:orderToken,expiresAt:now+1000*60*60*24}));
}else if(orderToken&&storedOrder&&storedOrder.token===orderToken&&Number(storedOrder.expiresAt)>now&&continuing){
  delyvreContext=true;
}else if(storedOrder&&Number(storedOrder.expiresAt)<=now){
  sessionRemove(orderContextKey);
}

if(delyvreContext){
  d.body.classList.add('delyvre-order-context');
  if(!d.querySelector('link[data-delyvre-order-style]')){
    const link=d.createElement('link');
    link.rel='stylesheet';
    link.dataset.delyvreOrderStyle='1';
    link.href=new URL('assets/delyvre-order.css',location.href).href;
    d.head.append(link);
  }
  const theme=d.querySelector('meta[name="theme-color"]');
  if(theme)theme.setAttribute('content','#ff4f3d');
  const homeUrl=new URL('delivery.php',location.href).href;
  const bar=d.createElement('div');
  bar.className='delyvre-order-bar';
  bar.innerHTML=`<div class="delyvre-order-bar-inner"><a class="delyvre-order-home" href="${homeUrl}" aria-label="Voltar ao DELYVRE">‹</a><a class="delyvre-order-brand" href="${homeUrl}" aria-label="DELYVRE — início"><span class="delyvre-order-brand-mark" aria-hidden="true">D</span><span class="delyvre-order-brand-copy">DELYVRE<small>Escolha. Peça. Receba.</small></span></a><span class="delyvre-order-spacer"></span><span class="delyvre-order-label">Acompanhar pedido</span></div>`;
  root.prepend(bar);

  const foot=d.querySelector('.foot');
  if(foot)foot.innerHTML='<b>DELYVRE</b> · Escolha. Peça. Receba.';

  d.querySelectorAll('.pay-method p').forEach(p=>{
    if(/servidor\s+EventMenu/i.test(p.textContent||'')){
      p.textContent='Os campos sensíveis são protegidos e tokenizados pelo provedor de pagamento. Número e CVV não ficam armazenados nesta página.';
    }
  });
  const secure=d.querySelector('.secure-note');
  if(secure)secure.innerHTML='🔒 <strong>Confirmação segura:</strong> o pedido só é marcado como pago depois da confirmação do provedor.';

  const trackingLink=d.querySelector('.tracking-action a');
  if(trackingLink){
    try{const url=new URL(trackingLink.href,location.href);url.searchParams.set('source','delyvre');trackingLink.href=url.href}catch{}
  }
}

const auto=root.dataset.autoRefresh==='1',indicator=d.querySelector('.sync-indicator');
let timer=null;
const syncState=()=>{if(!indicator)return;const offline=!navigator.onLine;indicator.classList.toggle('offline',offline);indicator.textContent=offline?'Sem conexão · tentando novamente':'Acompanhamento atualizado automaticamente'};
addEventListener('online',()=>{syncState();if(auto)schedule(1200)});
addEventListener('offline',syncState);
syncState();
function schedule(delay=15000){clearTimeout(timer);if(!auto)return;timer=setTimeout(()=>{if(d.hidden||!navigator.onLine){schedule(8000);return}indicator&&(indicator.textContent='Atualizando…');location.reload()},delay)}
d.addEventListener('visibilitychange',()=>{if(!d.hidden&&auto)schedule(1800)});
schedule();
d.querySelectorAll('[data-payment-form]').forEach(form=>form.addEventListener('submit',()=>{d.querySelectorAll('[data-payment-form] button').forEach(btn=>{btn.disabled=true;if(btn.form===form)btn.textContent='Abrindo pagamento…'})}));
})();