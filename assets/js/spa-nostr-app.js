// SPA Demo JavaScript — erwartet `window.NostrSignerConfig = { apiBase: '/wp-json', nonce: '...', meUrl: '/wp-json/nostr-signer/v1/me', signUrl: '/wp-json/nostr-signer/v1/sign-event' }`
import { relayInit } from 'nostr-tools';

const cfg = window.NostrSignerConfig || {
  apiBase: '/wp-json',
  nonce: null,
  meUrl: '/wp-json/nostr-signer/v1/me',
  signUrl: '/wp-json/nostr-signer/v1/sign-event'
};

const el = id => document.getElementById(id);

async function fetchJson(url, opts = {}){
  const options = {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    ...opts
  };
  if (cfg.nonce) options.headers['X-WP-Nonce'] = cfg.nonce;
  const r = await fetch(url, options);
  const body = await r.json().catch(()=>null);
  if (!r.ok) throw body || new Error('Serverfehler');
  return body;
}

async function loadMe(){
  el('btn-me').disabled = true;
  el('me-output').textContent = 'Lade...';
  try{
    const data = await fetchJson(cfg.meUrl, { method: 'GET' });
    el('me-output').textContent = JSON.stringify(data, null, 2);
  }catch(err){
    el('me-output').textContent = 'Fehler: ' + (err.message || JSON.stringify(err));
  }finally{ el('btn-me').disabled = false; }
}

async function signEventPayload(eventPayload, keyType='user', broadcast=false){
  const body = { event: eventPayload, key_type: keyType, broadcast };
  return fetchJson(cfg.signUrl, { method: 'POST', body: JSON.stringify(body) });
}

async function publishToRelay(relayUrl, event){
  const relay = relayInit(relayUrl);
  await relay.connect();
  return new Promise((resolve, reject)=>{
    const pub = relay.publish(event);
    pub.on('ok', () => resolve({ ok: true, relay: relayUrl }));
    pub.on('failed', (reason) => resolve({ ok: false, relay: relayUrl, reason }));
    // timeout in 10s
    setTimeout(()=> resolve({ ok: false, relay: relayUrl, reason: 'timeout' }), 10000);
  });
}

async function handleSignAndPublish(publish){
  el('btn-sign-publish').disabled = true;
  el('btn-sign-only').disabled = true;
  el('sign-output').textContent = 'Arbeite...';
  try{
    const content = el('event-content').value.trim();
    if (!content) throw new Error('Bitte Inhalt eingeben.');
    const kind = parseInt(el('event-kind').value || '1', 10) || 1;
    const tagsRaw = el('event-tags').value.trim();
    let tags = [];
    if (tagsRaw) {
      // Try JSON first
      try { tags = JSON.parse(tagsRaw); } catch(e){
        // fallback: parse newline separated tag,value pairs
        tags = tagsRaw.split(/\r?\n/).map(line => {
          const parts = line.split(',').map(p=>p.trim());
          return parts.length>1 ? [parts[0], parts.slice(1).join(',')] : [parts[0]];
        });
      }
    }

    const eventPayload = { kind, created_at: Math.floor(Date.now()/1000), tags, content };
    const keyType = el('key-type').value;
    const signedResp = await signEventPayload(eventPayload, keyType, false);
    el('sign-output').textContent = JSON.stringify(signedResp, null, 2);

    if (publish){
        const relayUrl = el('relay-url').value.trim();
      if (!relayUrl) throw new Error('Bitte Relay-URL angeben.');
      el('sign-output').textContent = el('sign-output').textContent + '\n\nPubliziere an Relay...';
      const relayResult = await publishToRelay(relayUrl, signedResp.event);
      el('sign-output').textContent = JSON.stringify({ signed: signedResp, relay: relayResult }, null, 2);
    }
  }catch(err){
    el('sign-output').textContent = 'Fehler: ' + (err.message || JSON.stringify(err));
  }finally{
    el('btn-sign-publish').disabled = false;
    el('btn-sign-only').disabled = false;
  }
}

document.addEventListener('DOMContentLoaded', ()=>{
  el('btn-me').addEventListener('click', loadMe);
  el('btn-sign-only').addEventListener('click', ()=>handleSignAndPublish(false));
  el('btn-sign-publish').addEventListener('click', ()=>handleSignAndPublish(true));
});
