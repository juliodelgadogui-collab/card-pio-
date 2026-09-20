(()=>{
'use strict';
const d=document;
const params=new URLSearchParams(location.search);
const route=params.get('route')||'dashboard';
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
   if(wrap.classList.contains('no-mobile-cards')||wrap.classList.contains('responsive-table'))return;
   const table=wrap.querySelector('table');if(!table)return;
   const headers=[...table.querySelectorAll('thead th')].map(th=>th.textContent.trim());if(!headers.length)return;
   [...table.querySelectorAll('tbody tr')].forEach(row=>[...row.children].forEach((cell,i)=>{if(cell.tagName==='TD'&&!cell.dataset.label)cell.dataset.label=headers[i]||''}));
   wrap.classList.add('responsive-table');
 });
}
function money(cents){return new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format((Number(cents)||0)/100)}
function link(routeName,tab=''){const u=new URL(location.href);u.search='';u.searchParams.set('route',routeName);if(tab)u.searchParams.set('tab',tab);return u.toString()}
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
enhanceStatuses();enhanceTables();enhancePlatformCockpit();
const observer=new MutationObserver(mutations=>{for(const m of mutations)for(const n of m.addedNodes)if(n.nodeType===1){enhanceStatuses(n);enhanceTables(n)}});observer.observe(d.body,{childList:true,subtree:true});
})();
