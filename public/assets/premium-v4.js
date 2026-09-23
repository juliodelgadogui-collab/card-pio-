(()=>{
'use strict';
const d=document;

/* Premium v5 is appended after the parsed page so it wins over legacy/page-local
   style blocks while preserving them as compatibility fallbacks. */
for(const file of ['premium-v5.css','premium-v5-runtime.css']){const css=d.createElement('link');css.rel='stylesheet';css.href=new URL('assets/'+file,location.href).toString();(d.body||d.documentElement).appendChild(css)}

const params=new URLSearchParams(location.search);
const path=(location.pathname.split('/').pop()||'').toLowerCase();
const directRoute=path==='whatsapp.php'?'whatsapp':path==='support.php'?'support':'';
const route=directRoute||params.get('route')||'dashboard';
d.body.classList.add('route-'+route.replace(/[^a-z0-9_-]/gi,'-'));

const normalizeText=s=>(s||'').trim().toLowerCase();
const statusMap=new Map([
 ['pending',['Aguardando','warning']],['new',['Aguardando','warning']],['novo',['Aguardando','warning']],['confirmed',['Aguardando','warning']],['confirmado',['Aguardando','warning']],
 ['preparing',['Em preparo','warning']],['preparando',['Em preparo','warning']],['ready',['Pronto','success']],['pronto',['Pronto','success']],
 ['out_for_delivery',['Em entrega','info']],['in_delivery',['Em entrega','info']],['completed',['Concluído','success']],['concluído',['Concluído','success']],
 ['cancelled',['Cancelado','danger']],['canceled',['Cancelado','danger']],['paid',['Pago','success']],['unpaid',['Não pago','warning']],
 ['created',['Aguardando pagamento','warning']],['authorized',['Aguardando pagamento','warning']],['processing',['Aguardando pagamento','warning']],['in_process',['Aguardando pagamento','warning']],
 ['failed',['Erro','danger']],['rejected',['Recusado','danger']],['refunded',['Estornado','info']],['partially_refunded',['Estorno parcial','info']],
 ['active',['Ativo','success']],['ativa',['Ativa','success']],['inactive',['Inativo','muted']],['suspended',['Suspenso','warning']],['suspensa',['Suspensa','warning']],
 ['overdue',['Vencida','danger']],['open',['Em aberto','warning']],['draft',['Rascunho','muted']],['ok',['Normal','success']],['healthy',['Normal','success']]
]);
function nodesIncludingRoot(root,selector){const out=[];if(root?.matches?.(selector))out.push(root);if(root?.querySelectorAll)out.push(...root.querySelectorAll(selector));return out}
function enhanceStatuses(root=d){
 nodesIncludingRoot(root,'.badge,.status-pill').forEach(el=>{
   if([...el.classList].some(c=>c.startsWith('status-')))return;
   const hit=statusMap.get(normalizeText(el.textContent));if(!hit)return;
   el.textContent=hit[0];el.classList.add('status-'+hit[1]);
 });
 nodesIncludingRoot(root,'.content td').forEach(el=>{
   if(el.children.length)return;
   const hit=statusMap.get(normalizeText(el.textContent));if(!hit)return;
   el.textContent=hit[0];
 });
}
function enhanceTables(root=d){
 nodesIncludingRoot(root,'.table-wrap').forEach(wrap=>{
   if(wrap.classList.contains('no-mobile-cards'))return;
   const table=wrap.querySelector('table');if(!table)return;
   const headers=[...table.querySelectorAll('thead th')].map(th=>th.textContent.trim());if(!headers.length)return;
   [...table.querySelectorAll('tbody tr')].forEach(row=>[...row.children].forEach((cell,i)=>{if(cell.tagName==='TD'&&!cell.dataset.label)cell.dataset.label=headers[i]||''}));
   wrap.classList.add('responsive-table');
 });
}
function money(cents){return new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format((Number(cents)||0)/100)}
function link(routeName,tab=''){const u=new URL(location.href);u.search='';u.searchParams.set('route',routeName);if(tab)u.searchParams.set('tab',tab);return u.toString()}
function ensurePlatformCouponNav(){
 if(!d.body.classList.contains('em-platform')||d.querySelector('.nav [data-route="marketplace-coupons"]'))return;
 const group=[...d.querySelectorAll('.nav-group')].find(g=>normalizeText(g.querySelector('.nav-group-title')?.textContent)==='eventmenu delivery');if(!group)return;
 const a=d.createElement('a');a.dataset.route='marketplace-coupons';a.href=link('marketplace-coupons');a.className=route==='marketplace-coupons'?'active':'';a.innerHTML='<span class="nav-icon" aria-hidden="true">%</span><span>Cupons Delivery</span>';group.appendChild(a);
}
function cockpitCard(label,value,detail,tone,href){const a=d.createElement('a');a.className='cockpit-card';a.href=href;const s=d.createElement('span');s.textContent=label;const strong=d.createElement('strong');strong.textContent=value;const small=d.createElement('small');small.textContent=detail;const badge=d.createElement('span');badge.className='badge status-'+tone;badge.textContent=tone==='danger'?'Atenção':tone==='warning'?'Revisar':tone==='success'?'Normal':'Abrir';a.append(s,strong,small,badge);return a}
async function enhancePlatformCockpit(){
 if(!d.body.classList.contains('em-platform')||route!=='super')return;
 const hero=d.querySelector('.page-hero');if(!hero||d.querySelector('[data-platform-cockpit]'))return;
 try{
   const url=new URL('api-platform-cockpit.php',location.href);
   const r=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});const body=await r.json();if(!r.ok||!body.ok)return;
   const x=body.data||{};
   const host=d.createElement('section');host.dataset.platformCockpit='1';
   const metrics=d.createElement('div');metrics.className='metric-grid finance-summary';
   const metric=(label,value,small,tone='')=>{const c=d.createElement('div');c.className='metric-card'+(tone?' metric-'+tone:'');c.innerHTML='<span></span><strong></strong><small></small>';c.querySelector('span').textContent=label;c.querySelector('strong').textContent=value;c.querySelector('small').textContent=small;return c};
   metrics.append(metric('MRR',money(x.mrr_cents),'Receita recorrente estimada','info'));
   metrics.append(metric('Empresas',String(x.companies_active||0),`${x.companies_total||0} no total`,'success'));
   metrics.append(metric('Volume processado',money(x.processed_cents),'Pedidos pagos na plataforma'));
   metrics.append(metric('Inadimplência',money(x.overdue_cents),`${x.invoices_overdue||0} fatura(s) vencida(s)`,(x.invoices_overdue||0)>0?'danger':'success'));
   const title=d.createElement('div');title.className='section-head';title.innerHTML='<div><span class="eyebrow">COCKPIT DA PLATAFORMA</span><h2>Operação geral</h2></div><span class="muted">Dados consolidados</span>';
   const grid=d.createElement('div');grid.className='platform-cockpit';
   grid.append(
     cockpitCard('EventMenu Delivery',String(x.delivery_active||0),'Empresas ativas no marketplace',(x.delivery_active||0)>0?'success':'muted',link('marketplace-finance','companies')),
     cockpitCard('Comissões',money(x.commissions_due_cents),'A receber / faturadas',(x.commissions_due_cents||0)>0?'warning':'success',link('marketplace-finance')),
     cockpitCard('Campanhas',String(x.campaigns_active||0),'Campanhas ativas no Delivery',(x.campaigns_active||0)>0?'info':'muted',link('marketplace-campaigns')),
     cockpitCard('Cupons Delivery','%','Criar descontos para clientes do app','info',link('marketplace-coupons')),
     cockpitCard('Impulsiona',money(x.impulsiona_balance_cents),`${x.impulsiona_companies||0} empresa(s) participante(s)`,(x.impulsiona_companies||0)>0?'info':'muted',link('marketplace-finance')),
     cockpitCard('Faturas em aberto',String(x.invoices_open||0),`${x.invoices_overdue||0} vencida(s)`,(x.invoices_overdue||0)>0?'danger':(x.invoices_open||0)>0?'warning':'success',link('marketplace-finance','invoices')),
     cockpitCard('Saúde do sistema',String(x.health_warnings||0),'Alertas que precisam de revisão',x.health==='error'?'danger':x.health==='warning'?'warning':'success',link('system-health'))
   );
   host.append(metrics,title,grid);hero.insertAdjacentElement('afterend',host);
   const warnings=[];if((x.invoices_overdue||0)>0)warnings.push(`${x.invoices_overdue} fatura(s) vencida(s)`);if((x.companies_suspended||0)>0)warnings.push(`${x.companies_suspended} empresa(s) suspensa(s)`);if((x.health_warnings||0)>0)warnings.push(`${x.health_warnings} alerta(s) de infraestrutura`);
   if(warnings.length){const alert=d.createElement('div');alert.className='alert warning';alert.textContent='Atenção: '+warnings.join(' · ')+'.';host.insertAdjacentElement('afterbegin',alert)}
   const oldMetrics=host.nextElementSibling;if(oldMetrics?.classList.contains('metric-grid'))oldMetrics.hidden=true;
 }catch(e){console.warn('Cockpit indisponível no momento.',e)}
}

async function applyCapabilities(){
 if(d.body.classList.contains('em-platform'))return;
 try{
   const endpoint=new URL('api-ui-capabilities.php',location.href);const r=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});if(!r.ok)return;const body=await r.json();if(!body.ok||!body.data)return;
   const disabled=new Set(body.data.disabled_routes||[]);
   d.querySelectorAll('.nav a[data-route]').forEach(a=>{if(disabled.has(a.dataset.route||''))a.remove()});
   d.querySelectorAll('.nav-group').forEach(g=>{if(!g.querySelector('a'))g.remove()});
   const modules=body.data.modules||{};
   const hideByHref=[['whatsapp.php','whatsapp'],['support.php','whatsapp'],['receipt-settings','printing'],['route=reports','reports'],['route=units','units']];
   d.querySelectorAll('a[href]').forEach(a=>{for(const [needle,module] of hideByHref){if(modules[module]===false&&a.getAttribute('href')?.includes(needle)){const card=a.closest('.card');(card||a).hidden=true;break}}});
 }catch(e){}
}

function enhanceSettingsHub(){
 if(route!=='settings')return;
 const grid=d.querySelector('.metric-grid');if(!grid||grid.dataset.v5Grouped)return;grid.dataset.v5Grouped='1';
 const cards=[...grid.children].filter(el=>el.matches('a.card'));
 const search=d.createElement('div');search.className='em-settings-search';search.innerHTML='<input type="search" aria-label="Buscar configuração" placeholder="Buscar configuração, WhatsApp, PIX, impressão..."><span class="em-live-indicator">Configurações da empresa</span>';grid.before(search);
 const categoryFor=card=>{const tag=normalizeText(card.querySelector('.eyebrow')?.textContent);if(/whatsapp|atendimento|e-mail/.test(tag))return['Comunicação','WhatsApp, atendimento e mensagens'];if(/pagamentos|pix/.test(tag))return['Pagamentos','Gateways e recebimentos'];if(/delivery|fidelidade|impressão/.test(tag))return['Operação','Venda, entrega e experiência'];return['Empresa','Identidade e configurações gerais']};
 const groups=new Map();for(const card of cards){const [name,desc]=categoryFor(card);if(!groups.has(name))groups.set(name,{desc,cards:[]});groups.get(name).cards.push(card)}
 grid.hidden=true;for(const [name,g] of groups){const section=d.createElement('section');section.className='em-settings-group';section.dataset.settingsGroup=name;section.innerHTML=`<div class="em-settings-group-title"><div><span class="eyebrow">${name.toUpperCase()}</span><h2>${name}</h2></div><span class="muted">${g.desc}</span></div><div class="em-settings-group-grid"></div>`;const host=section.querySelector('.em-settings-group-grid');g.cards.forEach(c=>host.appendChild(c));grid.before(section)}
 const input=search.querySelector('input');input?.addEventListener('input',()=>{const q=normalizeText(input.value);d.querySelectorAll('.em-settings-group').forEach(group=>{let shown=0;group.querySelectorAll('.card').forEach(card=>{const ok=!q||normalizeText(card.textContent).includes(q);card.hidden=!ok;if(ok)shown++});group.hidden=shown===0})});
}

function enhanceDashboardChart(){
 if(route!=='dashboard')return;d.querySelectorAll('.chart-bar[title]').forEach(bar=>{if(bar.querySelector('.em-chart-value'))return;const parts=(bar.getAttribute('title')||'').split('·');const value=(parts[1]||'').trim();if(!value)return;const tag=d.createElement('i');tag.className='em-chart-value';tag.textContent=value;bar.appendChild(tag)});
}

function enhanceSuperTenantEditor(){
 if(route!=='super')return;const editor=d.querySelector('#editar-empresa'),form=editor?.querySelector('form');if(!form||form.dataset.v5Tabs)return;form.dataset.v5Tabs='1';form.classList.add('em-tabbed-form');
 const children=[...form.children];const submit=children.find(el=>el.matches('button[type="submit"],button.primary'))||children.at(-1);const visible=children.filter(el=>el!==submit&&!el.matches('input[type="hidden"]'));
 const modulesAt=visible.findIndex(el=>normalizeText(el.textContent).includes('módulos ativos'));const adminAt=visible.findIndex(el=>normalizeText(el.textContent).includes('administrador da empresa'));
 if(modulesAt<0||adminAt<0)return;
 const tabs=d.createElement('div');tabs.className='em-form-tabs';tabs.setAttribute('role','tablist');
 const sections=[['Empresa e contrato',visible.slice(0,modulesAt)],['Módulos',visible.slice(modulesAt,adminAt)],['Administrador',visible.slice(adminAt)]];
 sections.forEach(([label,nodes],i)=>{const btn=d.createElement('button');btn.type='button';btn.textContent=label;btn.className=i===0?'active':'';btn.setAttribute('role','tab');const panel=d.createElement('section');panel.className='em-form-tabpanel'+(i===0?' active':'');panel.dataset.tab=String(i);nodes.forEach(n=>panel.appendChild(n));btn.addEventListener('click',()=>{tabs.querySelectorAll('button').forEach(x=>x.classList.remove('active'));form.querySelectorAll('.em-form-tabpanel').forEach(x=>x.classList.remove('active'));btn.classList.add('active');panel.classList.add('active')});tabs.appendChild(btn);form.appendChild(panel)});
 const firstPanel=form.querySelector('.em-form-tabpanel');if(firstPanel)form.insertBefore(tabs,firstPanel);if(submit){const action=d.createElement('div');action.className='em-form-submit';action.appendChild(submit);form.appendChild(action)}
}

function dashboardLiveRefresh(){
 if(route!=='dashboard')return;
 const section=d.querySelector('section[aria-label="Atenção agora"]');if(!section)return;
 const info=section.querySelector('.section-head .muted');if(info){info.className='em-live-indicator';info.textContent='Atualizado agora'}let last=Date.now(),busy=false;
 const attention={preparing:'Em preparo',ready:'Prontos',delivery:'Em entrega',overdue:'Atrasados',pending_payments:'Pagamentos',low_stock:'Estoque baixo'};
 const metric={sales:'Vendas do dia',orders:'Pedidos hoje',ticket_average:'Ticket médio',active_orders:'Em operação'};
 const setAttention=(label,value)=>{const card=[...d.querySelectorAll('.attention-card')].find(x=>normalizeText(x.querySelector('span')?.textContent)===normalizeText(label));if(card)card.querySelector('strong').textContent=String(value)};
 const setMetric=(label,value)=>{const card=[...d.querySelectorAll('.dashboard-metric')].find(x=>normalizeText(x.querySelector('.muted')?.textContent)===normalizeText(label));if(card)card.querySelector('strong').textContent=value};
 const mark=()=>{if(!info)return;const sec=Math.max(0,Math.floor((Date.now()-last)/1000));info.textContent=sec<5?'Atualizado agora':`Atualizado há ${sec}s`;info.classList.toggle('is-stale',sec>65)};setInterval(mark,5000);
 async function refresh(){if(busy||d.hidden)return;busy=true;try{const endpoint=new URL('api-dashboard-live.php',location.href);const r=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}),body=await r.json();if(!r.ok||!body.ok)return;const x=body.data||{};for(const [key,label] of Object.entries(attention))setAttention(label,x[key]??0);for(const [key,label] of Object.entries(metric))setMetric(label,['sales','ticket_average'].includes(key)?money(x[key]??0):String(x[key]??0));const quick=d.querySelector('.quick-grid .quick-card');if(quick){const strong=quick.querySelector('strong'),span=quick.querySelector('span');if(strong)strong.textContent=`${x.occupied_tables||0} mesas ocupadas`;if(span)span.textContent=`${x.open_tabs||0} comandas abertas nesta unidade.`}last=Date.now();mark()}catch(e){if(info)info.classList.add('is-stale')}finally{busy=false}}
 setInterval(refresh,30000);d.addEventListener('visibilitychange',()=>{if(!d.hidden&&Date.now()-last>30000)refresh()});
}

function improveDialogs(){d.querySelectorAll('dialog').forEach(dialog=>{dialog.addEventListener('click',e=>{const rect=dialog.getBoundingClientRect();if(e.clientX<rect.left||e.clientX>rect.right||e.clientY<rect.top||e.clientY>rect.bottom)dialog.close()})})}

ensurePlatformCouponNav();enhanceStatuses();enhanceTables();enhanceSettingsHub();enhanceDashboardChart();enhanceSuperTenantEditor();enhancePlatformCockpit();applyCapabilities();dashboardLiveRefresh();improveDialogs();
const observer=new MutationObserver(mutations=>{for(const m of mutations)for(const n of m.addedNodes)if(n.nodeType===1){enhanceStatuses(n);enhanceTables(n)}});observer.observe(d.body,{childList:true,subtree:true});
})();
