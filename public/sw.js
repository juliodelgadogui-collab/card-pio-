const CACHE='eventmenu-static-v2';
const scopeUrl=new URL(self.registration.scope);
const BASE=scopeUrl.pathname.endsWith('/')?scopeUrl.pathname:scopeUrl.pathname+'/';
const at=path=>BASE+path.replace(/^\/+/, '');
const STATIC=[at('assets/app.css'),at('assets/icon.svg'),at('offline.html'),at('manifest.webmanifest')];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(STATIC)).then(()=>self.skipWaiting()));});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));});
self.addEventListener('fetch',event=>{
  const req=event.request;if(req.method!=='GET')return;
  const url=new URL(req.url);if(url.origin!==self.location.origin)return;
  if(req.mode==='navigate'){
    event.respondWith(fetch(req,{cache:'no-store'}).catch(()=>caches.match(at('offline.html'))));return;
  }
  if(url.pathname.startsWith(at('assets/'))||url.pathname===at('manifest.webmanifest')){
    event.respondWith(caches.match(req).then(cached=>cached||fetch(req).then(res=>{if(res.ok){const copy=res.clone();caches.open(CACHE).then(c=>c.put(req,copy));}return res;})));
  }
});
