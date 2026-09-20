'use strict';

const http=require('http');
const crypto=require('crypto');
const fs=require('fs');
const path=require('path');
const wppconnect=require('@wppconnect-team/wppconnect');

const HOST=process.env.HOST||'127.0.0.1';
const PORT=Number(process.env.PORT||21466);
const SECRET=String(process.env.EVENTMENU_WHATSAPP_BRIDGE_SECRET||'');
const SESSION_ROOT=path.resolve(process.env.WHATSAPP_SESSION_ROOT||path.join(__dirname,'storage','sessions'));
const CHROME_PATH=String(process.env.CHROME_EXECUTABLE_PATH||'').trim();
const NO_SANDBOX=String(process.env.WHATSAPP_CHROME_NO_SANDBOX||'').toLowerCase()==='true';
const MAX_BODY=32*1024;
const SESSION_RE=/^[a-f0-9]{64}$/;
const sessions=new Map();

if(!SECRET||SECRET.length<32){console.error('EVENTMENU_WHATSAPP_BRIDGE_SECRET deve possuir ao menos 32 caracteres.');process.exit(1);}
if(!Number.isInteger(PORT)||PORT<1||PORT>65535){console.error('PORT inválida.');process.exit(1);}
fs.mkdirSync(SESSION_ROOT,{recursive:true,mode:0o700});try{fs.chmodSync(SESSION_ROOT,0o700);}catch(_){}
const tokenStore=new wppconnect.tokenStore.FileTokenStore({path:SESSION_ROOT});

function json(res,status,payload){const body=JSON.stringify(payload);res.writeHead(status,{'Content-Type':'application/json; charset=utf-8','Content-Length':Buffer.byteLength(body),'Cache-Control':'no-store','X-Content-Type-Options':'nosniff'});res.end(body);}
function authorized(req){const header=String(req.headers.authorization||'');if(!header.startsWith('Bearer '))return false;const received=Buffer.from(header.slice(7)),expected=Buffer.from(SECRET);return received.length===expected.length&&crypto.timingSafeEqual(received,expected);}
function stateFor(key){if(!sessions.has(key))sessions.set(key,{key,status:'disconnected',qr:null,phone:'',error:'',client:null,starting:null,reconnectTimer:null,reconnectAttempt:0,manualStop:false,updatedAt:new Date().toISOString()});return sessions.get(key);}
function publicState(state){return{ok:true,status:state.status,qr:state.qr,phone:state.phone,error:state.error||null,updated_at:state.updatedAt};}
function normalizeStatus(value){const raw=String(value||'').toLowerCase();if(['islogged','inchat','qrreadsuccess','connected'].includes(raw))return'connected';if(['notlogged','disconnectedmobile','desconnectedmobile','serverclose','browserclose','autoclosecalled','delete_token','device_not_connected','phonenotconnected'].includes(raw))return'disconnected';if(['qrreadfail','qrreaderror'].includes(raw))return'qr';if(['syncing','opening','starting'].includes(raw))return'starting';return raw.includes('qr')?'qr':(raw.includes('connect')?'reconnecting':'starting');}
async function refreshPhone(state){if(!state.client)return;try{const host=await state.client.getHostDevice();const raw=host?.wid?._serialized||host?.wid?.user||host?.id?._serialized||host?.id?.user||'';const phone=String(raw).replace(/@.+$/,'').replace(/\D+/g,'');if(/^\d{10,15}$/.test(phone))state.phone=phone;}catch(_){}}
function cancelReconnect(state){if(state.reconnectTimer)clearTimeout(state.reconnectTimer);state.reconnectTimer=null;}
function scheduleReconnect(state){if(state.manualStop||state.starting||state.reconnectTimer)return;const attempt=Math.min(state.reconnectAttempt+1,8);state.reconnectAttempt=attempt;const delay=Math.min(60000,2000*(2**(attempt-1)));state.status='reconnecting';state.updatedAt=new Date().toISOString();state.reconnectTimer=setTimeout(()=>{state.reconnectTimer=null;startSession(state.key).catch(()=>{});},delay);}

async function startSession(key){
  if(!SESSION_RE.test(key))throw new Error('Sessão inválida.');const state=stateFor(key);state.manualStop=false;cancelReconnect(state);if(state.client&&state.status==='connected')return state;if(state.starting)return state.starting;
  state.status='starting';state.error='';state.qr=null;state.updatedAt=new Date().toISOString();const browserArgs=NO_SANDBOX?['--no-sandbox','--disable-setuid-sandbox']:[];
  const promise=wppconnect.create({session:key,tokenStore,headless:true,logQR:false,autoClose:0,disableWelcome:true,browserArgs,...(CHROME_PATH?{puppeteerOptions:{executablePath:CHROME_PATH}}:{}),catchQR:(base64Qr)=>{state.qr=typeof base64Qr==='string'&&base64Qr.startsWith('data:image/')?base64Qr:null;state.status='qr';state.error='';state.updatedAt=new Date().toISOString();},statusFind:(status)=>{const normalized=normalizeStatus(status);state.status=normalized;state.updatedAt=new Date().toISOString();if(normalized==='connected'){state.qr=null;state.error='';state.reconnectAttempt=0;refreshPhone(state).catch(()=>{});}else if(normalized==='disconnected'&&!state.manualStop)scheduleReconnect(state);}})
    .then(async client=>{state.client=client;state.status='connected';state.qr=null;state.error='';state.reconnectAttempt=0;state.updatedAt=new Date().toISOString();await refreshPhone(state);return state;})
    .catch(error=>{state.client=null;state.status=state.qr?'qr':'error';state.error=String(error?.message||error||'Falha ao iniciar sessão.').slice(0,400);state.updatedAt=new Date().toISOString();throw error;})
    .finally(()=>{state.starting=null;if(!state.manualStop&&['disconnected','error'].includes(state.status))scheduleReconnect(state);});
  state.starting=promise;return promise;
}
async function logoutSession(key){const state=stateFor(key);state.manualStop=true;cancelReconnect(state);try{if(state.client){try{await state.client.logout();}catch(_){try{await state.client.close();}catch(_){}}}try{await tokenStore.removeToken(key);}catch(_){}}finally{state.client=null;state.qr=null;state.phone='';state.error='';state.status='disconnected';state.reconnectAttempt=0;state.updatedAt=new Date().toISOString();}return state;}
async function readBody(req){const chunks=[];let size=0;for await(const chunk of req){size+=chunk.length;if(size>MAX_BODY)throw new Error('Payload grande demais.');chunks.push(chunk);}if(!chunks.length)return{};const parsed=JSON.parse(Buffer.concat(chunks).toString('utf8'));return parsed&&typeof parsed==='object'&&!Array.isArray(parsed)?parsed:{};}
async function sendMessage(key,body){const state=stateFor(key);if(!state.client||state.status!=='connected')throw new Error('WhatsApp não conectado.');const phone=String(body.phone||'').replace(/\D+/g,''),message=String(body.message||'').trim();if(!/^\d{12,15}$/.test(phone))throw new Error('Telefone inválido.');if(!message||message.length>2000)throw new Error('Mensagem inválida.');const result=await state.client.sendText(`${phone}@c.us`,message);const messageId=result?.id?._serialized||result?.id||result?.key?.id||'';return{ok:true,message_id:String(messageId||'')};}

const server=http.createServer(async(req,res)=>{try{
  if(!authorized(req))return json(res,401,{ok:false,error:'Não autorizado.'});const url=new URL(req.url||'/',`http://${HOST}:${PORT}`);
  if(req.method==='GET'&&url.pathname==='/health'){let saved=0;try{saved=(await tokenStore.listTokens()).filter(k=>SESSION_RE.test(String(k))).length;}catch(_){}return json(res,200,{ok:true,service:'eventmenu-whatsapp-bridge',sessions:sessions.size,saved_sessions:saved});}
  const match=url.pathname.match(/^\/v1\/sessions\/([a-f0-9]{64})(?:\/(start|logout|send))?$/);if(!match)return json(res,404,{ok:false,error:'Rota não encontrada.'});const key=match[1],action=match[2]||'';
  if(req.method==='GET'&&!action)return json(res,200,publicState(stateFor(key)));
  if(req.method==='POST'&&action==='start'){const state=stateFor(key);startSession(key).catch(()=>{});return json(res,202,publicState(state));}
  if(req.method==='POST'&&action==='logout')return json(res,200,publicState(await logoutSession(key)));
  if(req.method==='POST'&&action==='send')return json(res,200,await sendMessage(key,await readBody(req)));
  return json(res,405,{ok:false,error:'Método não permitido.'});
}catch(error){return json(res,500,{ok:false,error:String(error?.message||'Erro interno.').slice(0,400)});}});

server.listen(PORT,HOST,()=>{console.log(`EventMenu WhatsApp Bridge em http://${HOST}:${PORT}`);console.log(`Sessões protegidas em ${SESSION_ROOT}`);if(NO_SANDBOX)console.warn('ATENÇÃO: Chromium executando com sandbox desativado por configuração explícita.');Promise.resolve(tokenStore.listTokens()).then(keys=>{for(const key of keys.filter(k=>SESSION_RE.test(String(k))))setTimeout(()=>startSession(String(key)).catch(()=>{}),250);}).catch(()=>{});});
for(const signal of['SIGINT','SIGTERM'])process.on(signal,async()=>{server.close();for(const state of sessions.values()){state.manualStop=true;cancelReconnect(state);try{await state.client?.close();}catch(_){}}process.exit(0);});
