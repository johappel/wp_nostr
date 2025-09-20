import { nip19 } from 'https://esm.sh/nostr-tools@2.7.2?target=es2022';
import { configureNostr } from './nostr-app.js';

const cfg = window.NostrSignerConfig || {};
cfg.defaultRelays = Array.isArray(cfg.defaultRelays) && cfg.defaultRelays.length
  ? cfg.defaultRelays
  : ['wss://relay.damus.io', 'wss://relay.snort.social'];
cfg.loginUrl = cfg.loginUrl || '/wp-login.php';
cfg.logoutUrl = cfg.logoutUrl || '/wp-login.php?action=logout';
cfg.meUrl = cfg.meUrl || (cfg.apiBase ? `${cfg.apiBase}/nostr-signer/v1/me` : '/wp-json/nostr-signer/v1/me');
cfg.signUrl = cfg.signUrl || (cfg.apiBase ? `${cfg.apiBase}/nostr-signer/v1/sign-event` : '/wp-json/nostr-signer/v1/sign-event');

const STORAGE_KEYS = {
  relays: 'nostr-spa-relays',
  kind: 'nostr-spa-kind',
  content: 'nostr-spa-content',
  tags: 'nostr-spa-tags',
  signAs: 'nostr-spa-sign-as',
  blogPubkey: 'nostr-spa-blog-pubkey',
  filterKind: 'nostr-spa-filter-kind',
  filterScope: 'nostr-spa-filter-scope',
  limit: 'nostr-spa-limit'
};

const storage = {
  get(key, fallback = null) {
    try {
      const value = localStorage.getItem(key);
      return value === null ? fallback : JSON.parse(value);
    } catch (error) {
      console.warn('Konnte localStorage-Wert nicht lesen', key, error);
      return fallback;
    }
  },
  set(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (error) {
      console.warn('Konnte localStorage-Wert nicht setzen', key, error);
    }
  }
};

const state = {
  isLoggedIn: false,
  profile: null,
  relays: [],
  eventsMap: new Map(),
  filter: {
    kind: '',
    scope: 'both'
  },
  limit: 100,
  relayStatus: new Map(),
  refreshTimer: null,
  blogPubkey: null,
  userPubkey: null,
  activeSubscriptions: []
};
let nostr = null;

const ui = {
  loginLink: document.getElementById('login-link'),
  logoutLink: document.getElementById('logout-link'),
  authStatus: document.getElementById('auth-status'),
  profileCard: document.getElementById('profile-card'),
  profileAvatar: document.getElementById('profile-avatar'),
  profileName: document.getElementById('profile-name'),
  profileMeta: document.getElementById('profile-meta'),
  profileStatus: document.getElementById('profile-status'),
  profileStatusText: document.getElementById('profile-status-text'),
  relayInput: document.getElementById('relay-input'),
  eventKind: document.getElementById('event-kind'),
  signAs: document.getElementById('sign-as'),
  blogPubkey: document.getElementById('blog-pubkey'),
  tagList: document.getElementById('tag-list'),
  addTagButton: document.getElementById('add-tag-button'),
  content: document.getElementById('event-content'),
  publishButton: document.getElementById('publish-button'),
  signOnlyButton: document.getElementById('sign-only-button'),
  publishStatus: document.getElementById('publish-status'),
  publishSummary: document.getElementById('publish-summary'),
  publishResults: document.getElementById('publish-results'),
  filterKind: document.getElementById('filter-kind'),
  filterForm: document.getElementById('filter-form'),
  filterScope: document.querySelectorAll('input[name="filter-scope"]'),
  eventLimit: document.getElementById('event-limit'),
  refreshEvents: document.getElementById('refresh-events'),
  clearEvents: document.getElementById('clear-events'),
  relayStatusList: document.getElementById('relay-status-list'),
  eventsList: document.getElementById('events-list'),
  loadMore: document.getElementById('load-more-button'),
  toastContainer: document.getElementById('toast-container'),
  tagTemplate: document.getElementById('tag-row-template')
};

function showToast(message, type = 'info', detail) {
  const toast = document.createElement('div');
  toast.className = `toast${type === 'error' ? ' error' : type === 'success' ? ' success' : ''}`;
  toast.textContent = message;
  if (detail) {
    const detailSpan = document.createElement('span');
    detailSpan.className = 'toast-detail';
    detailSpan.textContent = detail;
    toast.appendChild(detailSpan);
  }
  ui.toastContainer.appendChild(toast);
  const removeToast = () => {
    if (toast.isConnected) {
      toast.remove();
    }
  };
  setTimeout(() => {
    toast.classList.add('hide');
    toast.addEventListener('transitionend', removeToast, { once: true });
    setTimeout(removeToast, 500);
  }, 6000);
}

function formatDate(timestamp) {
  if (!timestamp) {
    return '-';
  }
  try {
    const date = new Date(timestamp * 1000);
    return new Intl.DateTimeFormat('de-DE', {
      dateStyle: 'medium',
      timeStyle: 'medium'
    }).format(date);
  } catch (error) {
    return String(timestamp);
  }
}

function parseRelayInput(value) {
  return value
    .split(/[\s,]+/)
    .map((entry) => entry.trim())
    .filter(Boolean);
}

function validateRelay(url) {
  return /^wss:\/\//i.test(url);
}

function collectTagRows(forEvent = true) {
  const rows = Array.from(ui.tagList.querySelectorAll('.tag-row'));
  const result = [];
  rows.forEach((row) => {
    const type = row.querySelector('.tag-type').value.trim();
    const value = row.querySelector('.tag-value').value.trim();
    const extra = row.querySelector('.tag-extra').value.trim();
    if (!type && !value && !extra) {
      return;
    }
    if (forEvent && (!type || !value)) {
      throw new Error('Jeder Tag benoetigt mindestens Typ und Wert.');
    }
    const extras = extra
      ? extra
          .split(',')
          .map((item) => item.trim())
          .filter(Boolean)
      : [];
    if (forEvent) {
      result.push([type, value, ...extras]);
    } else {
      result.push({ type, value, extras });
    }
  });
  return result;
}

function renderTagRowsFromStorage() {
  ui.tagList.innerHTML = '';
  const stored = storage.get(STORAGE_KEYS.tags, []);
  if (Array.isArray(stored) && stored.length) {
    stored.forEach((tag) => addTagRow(tag));
  } else {
    addTagRow();
  }
}

function addTagRow(tag = { type: '', value: '', extras: [] }) {
  const fragment = document.importNode(ui.tagTemplate.content, true);
  const row = fragment.querySelector('.tag-row');
  const typeInput = row.querySelector('.tag-type');
  const valueInput = row.querySelector('.tag-value');
  const extraInput = row.querySelector('.tag-extra');
  typeInput.value = tag.type || '';
  valueInput.value = tag.value || '';
  if (Array.isArray(tag.extras)) {
    extraInput.value = tag.extras.join(', ');
  } else if (typeof tag.extras === 'string') {
    extraInput.value = tag.extras;
  }
  row.querySelector('.remove-tag').addEventListener('click', () => {
    row.remove();
    persistTags();
  });
  [typeInput, valueInput, extraInput].forEach((input) => {
    input.addEventListener('change', persistTags);
    input.addEventListener('blur', persistTags);
  });
  ui.tagList.appendChild(row);
}

function persistTags() {
  try {
    const tags = collectTagRows(false);
    storage.set(STORAGE_KEYS.tags, tags);
  } catch (error) {
    showToast(error.message, 'error');
  }
}


function updateAuthControls() {
  if (state.isLoggedIn) {
    ui.authStatus.textContent = state.profile?.user?.display_name
      ? `Angemeldet als ${state.profile.user.display_name}`
      : 'Angemeldet';
    ui.loginLink.hidden = true;
    ui.logoutLink.hidden = false;
    ui.publishButton.disabled = false;
    ui.signOnlyButton.disabled = false;
  } else {
    ui.authStatus.textContent = 'Nicht angemeldet';
    ui.loginLink.hidden = false;
    ui.logoutLink.hidden = true;
    ui.publishButton.disabled = true;
    ui.signOnlyButton.disabled = true;
  }
  ui.loginLink.href = cfg.loginUrl;
  ui.logoutLink.href = cfg.logoutUrl;
}

function renderProfile(data) {
  if (!data) {
    ui.profileCard.hidden = true;
    ui.profileStatusText.textContent = 'Keine Sitzungsdaten verfuegbar.';
    return;
  }
  state.profile = data;
  state.isLoggedIn = true;
  ui.profileStatus.hidden = true;
  ui.profileCard.hidden = false;
  const name = data.user?.display_name || data.user?.username || 'Unbekannter Benutzer';
  ui.profileName.textContent = name;
  const avatarUrl = data.user?.avatar_url || `${cfg.pluginUrl || ''}assets/images/avatar-placeholder.png`;
  ui.profileAvatar.src = avatarUrl;
  ui.profileAvatar.alt = `Avatar von ${name}`;
  ui.profileMeta.innerHTML = '';
  const metaEntries = [];
  if (data.user?.pubkey?.npub) {
    metaEntries.push({ label: 'Meine npub', value: data.user.pubkey.npub });
    state.userPubkey = data.user.pubkey.hex || null;
  }
  if (data.blog?.pubkey?.npub) {
    metaEntries.push({ label: 'Blog npub', value: data.blog.pubkey.npub });
    state.blogPubkey = data.blog.pubkey.hex || null;
    if (!ui.blogPubkey.value.trim()) {
      ui.blogPubkey.value = data.blog.pubkey.npub;
    }
  }
  if (data.user?.nip05) {
    metaEntries.push({ label: 'NIP-05', value: data.user.nip05 });
  }
  if (data.blog?.home_url) {
    metaEntries.push({ label: 'Blog URL', value: data.blog.home_url });
  }
  metaEntries.forEach((entry) => {
    const span = document.createElement('span');
    span.className = 'chip';
    span.textContent = `${entry.label}: ${entry.value}`;
    ui.profileMeta.appendChild(span);
  });
  updateAuthControls();
}

async function loadProfile() {
  ui.profileStatus.hidden = false;
  ui.profileStatusText.textContent = 'Verbinde mit /me ...';
  try {
    const data = await nostr.getProfile();
    renderProfile(data);
    showToast('Profil geladen', 'success');
  } catch (error) {
    state.isLoggedIn = false;
    state.profile = null;
    state.userPubkey = null;
    ui.profileStatus.hidden = false;
    ui.profileStatusText.textContent = `Profil konnte nicht geladen werden: ${error.message}`;
    updateAuthControls();
  }
}

function updateRelayStatus(url, status, message) {
  state.relayStatus.set(url, {
    status,
    message,
    timestamp: Date.now()
  });
  renderRelayStatus();
}

function renderRelayStatus() {
  ui.relayStatusList.innerHTML = '';
  const entries = Array.from(state.relayStatus.entries()).sort(([a], [b]) => a.localeCompare(b));
  if (!entries.length) {
    const empty = document.createElement('p');
    empty.className = 'muted';
    empty.textContent = 'Noch keine Relays konfiguriert.';
    ui.relayStatusList.appendChild(empty);
    return;
  }
  entries.forEach(([url, info]) => {
    const pill = document.createElement('div');
    pill.className = 'relay-pill';
    pill.dataset.status = info.status || 'offline';
    const statusText = info.status === 'ok'
      ? 'verbunden'
      : info.status === 'error'
        ? 'Fehler'
        : info.status === 'connecting'
          ? 'verbindet ...'
          : 'offline';
    pill.innerHTML = `<span>${url}</span><span>${statusText}${info.message ? ` - ${info.message}` : ''}</span>`;
    ui.relayStatusList.appendChild(pill);
  });
}

async function ensureRelay(url) {
  updateRelayStatus(url, 'connecting');
  try {
    const relay = await nostr.ensureRelay(url);
    if (relay?.connected) {
      updateRelayStatus(url, 'ok');
    }
    return relay;
  } catch (error) {
    updateRelayStatus(url, 'error', error?.message || 'Verbindungsfehler');
    throw error;
  }
}
function buildFilter() {
  const filter = {};
  const kindValue = state.filter.kind ? Number(state.filter.kind) : null;
  if (Number.isInteger(kindValue) && kindValue >= 0) {
    filter.kinds = [kindValue];
  }
  const authors = [];
  if (state.filter.scope === 'user' || state.filter.scope === 'both') {
    if (state.userPubkey) {
      authors.push(state.userPubkey);
    }
  }
  if (state.filter.scope === 'blog' || state.filter.scope === 'both') {
    if (state.blogPubkey) {
      authors.push(state.blogPubkey);
    }
  }
  // wait 3 seconds after last change before showing toast
  setTimeout(() => {
    if (state.filter.scope === 'user' && !state.userPubkey) {
      showToast('Eigenes Profil noch nicht geladen. Bitte erneut versuchen.', 'error');
    }
  }, 3000);
  
  if (state.filter.scope === 'blog' && !state.blogPubkey) {
    showToast('Blog-Pubkey nicht bekannt. Bitte unter Profil pruefen.', 'error');
  }
  if (state.filter.scope !== 'all' && authors.length) {
    filter.authors = Array.from(new Set(authors));
  }
  filter.limit = state.limit;
  return filter;
}

function createRelayFilter(filter) {
  const cleaned = {};
  if (Array.isArray(filter.kinds) && filter.kinds.length) {
    cleaned.kinds = filter.kinds.map((kind) => Number(kind)).filter((kind) => Number.isInteger(kind));
  }
  if (Array.isArray(filter.authors) && filter.authors.length) {
    cleaned.authors = filter.authors.filter((author) => typeof author === 'string' && author !== '');
  }
  const limitValue = Number(filter.limit);
  if (Number.isInteger(limitValue) && limitValue > 0) {
    cleaned.limit = limitValue;
  }
  return cleaned;
}

function renderEvents() {
  const events = Array.from(state.eventsMap.values()).sort((a, b) => (b.created_at || 0) - (a.created_at || 0));
  const limited = events.slice(0, state.limit);
  ui.eventsList.innerHTML = '';
  if (!limited.length) {
    const empty = document.createElement('p');
    empty.className = 'muted';
    empty.textContent = 'Noch keine Events geladen.';
    ui.eventsList.appendChild(empty);
    return;
  }
  limited.forEach((event) => {
    const item = document.createElement('article');
    item.className = 'event-item';
    const meta = document.createElement('div');
    meta.className = 'event-meta';
    const dateSpan = document.createElement('span');
    dateSpan.textContent = formatDate(event.created_at);
    meta.appendChild(dateSpan);
    const kindSpan = document.createElement('span');
    kindSpan.textContent = `Kind ${event.kind}`;
    meta.appendChild(kindSpan);
    const authorSpan = document.createElement('span');
    authorSpan.textContent = event.pubkey ? shortenPubkey(event.pubkey) : 'Unbekannter Autor';
    meta.appendChild(authorSpan);
    item.appendChild(meta);

    if (event.content) {
      const content = document.createElement('p');
      content.className = 'event-content';
      const isLong = event.content.length > 280;
      content.textContent = isLong ? `${event.content.slice(0, 280)}...` : event.content;
      item.appendChild(content);
      if (isLong) {
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn secondary small';
        toggle.textContent = 'Mehr anzeigen';
        let expanded = false;
        toggle.addEventListener('click', () => {
          expanded = !expanded;
          content.textContent = expanded ? event.content : `${event.content.slice(0, 280)}...`;
          toggle.textContent = expanded ? 'Weniger anzeigen' : 'Mehr anzeigen';
        });
        item.appendChild(toggle);
      }
    }

    const details = document.createElement('details');
    const summary = document.createElement('summary');
    summary.textContent = 'Event-JSON anzeigen';
    const pre = document.createElement('pre');
    pre.className = 'json-output';
    pre.textContent = JSON.stringify(event, null, 2);
    details.appendChild(summary);
    details.appendChild(pre);
    item.appendChild(details);

    ui.eventsList.appendChild(item);
  });
}

function shortenPubkey(pubkey) {
  if (!pubkey) {
    return '';
  }
  if (pubkey.startsWith('npub')) {
    return `${pubkey.slice(0, 10)}...${pubkey.slice(-4)}`;
  }
  if (pubkey.length > 12) {
    return `${pubkey.slice(0, 8)}...${pubkey.slice(-4)}`;
  }
  return pubkey;
}

function addEvents(events) {
  let added = false;
  events.forEach((event) => {
    if (event && event.id && !state.eventsMap.has(event.id)) {
      state.eventsMap.set(event.id, event);
      added = true;
    }
  });
  if (added) {
    renderEvents();
  }
}

async function fetchEventsFromRelay(url, filter) {
  try {
    await ensureRelay(url);
    const relayFilter = createRelayFilter(filter);
    const events = await nostr.fetchEvents(relayFilter, [url], { timeout: 5000 });
    return Array.isArray(events) ? events : [];
  } catch (error) {
    updateRelayStatus(url, 'error', error?.message || 'Verbindungsfehler');
    throw error;
  }
}

function clearActiveSubscriptions() {
  state.activeSubscriptions.forEach((unsubscribe) => {
    try {
      unsubscribe?.();
    } catch (error) {
      // ignoriert
    }
  });
  state.activeSubscriptions = [];
  try {
    nostr?.clearActiveSubscriptions();
  } catch (error) {
    // ignoriert
  }
}
async function subscribeToEvents(relayList, filter) {
  clearActiveSubscriptions();
  const relays = Array.isArray(relayList) ? relayList : [];
  relays.forEach((url) => updateRelayStatus(url, 'connecting'));
  try {
    const relayFilter = createRelayFilter(filter);
    const unsubscribe = await nostr.subscribe(relayFilter, relays, {
      onEvent(event) {
        if (event && event.id) {
          state.eventsMap.set(event.id, event);
          renderEvents();
        }
      },
      onEose(relayUrl) {
        updateRelayStatus(relayUrl, 'ok');
      },
      onError(error, relayUrl) {
        updateRelayStatus(relayUrl, 'error', error?.message || 'Fehler');
      },
      onClose(reason, relayUrl) {
        if (reason && reason !== 'closed') {
          const message = typeof reason === 'string' ? reason : 'Subscription beendet';
          updateRelayStatus(relayUrl, 'error', message);
        }
      }
    });
    state.activeSubscriptions.push(unsubscribe);
  } catch (error) {
    relays.forEach((url) => updateRelayStatus(url, 'error', error?.message || 'Subscription fehlgeschlagen'));
    throw error;
  }
}
function loadStoredValues() {
  const storedRelays = storage.get(STORAGE_KEYS.relays, cfg.defaultRelays);
  if (Array.isArray(storedRelays) && storedRelays.length) {
    state.relays = storedRelays;
    ui.relayInput.value = storedRelays.join(', ');
  }

  const storedKind = storage.get(STORAGE_KEYS.kind, 1);
  ui.eventKind.value = storedKind;

  const storedContent = storage.get(STORAGE_KEYS.content, '');
  ui.content.value = storedContent;

  const storedSignAs = storage.get(STORAGE_KEYS.signAs, 'user');
  ui.signAs.value = storedSignAs;

  const storedBlog = storage.get(STORAGE_KEYS.blogPubkey, '');
  if (storedBlog) {
    ui.blogPubkey.value = storedBlog;
  }

  const storedFilterKind = storage.get(STORAGE_KEYS.filterKind, '');
  ui.filterKind.value = storedFilterKind;
  state.filter.kind = storedFilterKind;

  const storedFilterScope = storage.get(STORAGE_KEYS.filterScope, 'both');
  state.filter.scope = storedFilterScope;
  ui.filterScope.forEach((input) => {
    input.checked = input.value === storedFilterScope;
  });

  const storedLimit = storage.get(STORAGE_KEYS.limit, 100);
  state.limit = Number(storedLimit) || 100;
  ui.eventLimit.value = state.limit;
  if (nostr && state.relays.length) {
    nostr.updateConfig({ defaultRelays: state.relays });
  }
}
async function refreshEvents() {
  if (state.refreshTimer) {
    clearTimeout(state.refreshTimer);
    state.refreshTimer = null;
  }
  const relayList = state.relays;
  if (!relayList.length) {
    state.eventsMap.clear();
    renderEvents();
    return;
  }
  const filter = buildFilter();
  const previousEvents = new Map(state.eventsMap);
  state.eventsMap.clear();
  renderEvents();
  try {
    const batches = await Promise.all(
      relayList.map((url) =>
        fetchEventsFromRelay(url, filter).catch((error) => {
          showToast(`Relay ${url}: ${error?.message || 'Fehler beim Laden'}`, 'error');
          return [];
        })
      )
    );
    addEvents(batches.flat());
    await subscribeToEvents(relayList, filter);
    showToast('Events aktualisiert', 'success');
  } catch (error) {
    state.eventsMap = previousEvents;
    renderEvents();
    showToast(`Events konnten nicht geladen werden: ${error?.message || error}`, 'error');
  }
}

function persistFormState() {
  storage.set(STORAGE_KEYS.relays, state.relays);
  storage.set(STORAGE_KEYS.kind, Number(ui.eventKind.value) || 1);
  storage.set(STORAGE_KEYS.content, ui.content.value);
  storage.set(STORAGE_KEYS.signAs, ui.signAs.value);
  storage.set(STORAGE_KEYS.blogPubkey, ui.blogPubkey.value.trim());
}

function persistFilterState() {
  storage.set(STORAGE_KEYS.filterKind, state.filter.kind || '');
  storage.set(STORAGE_KEYS.filterScope, state.filter.scope);
  storage.set(STORAGE_KEYS.limit, state.limit);
}

function updateRelaysFromInput() {
  const relays = parseRelayInput(ui.relayInput.value);
  const invalid = relays.filter((url) => !validateRelay(url));
  if (invalid.length) {
    showToast(`Ungueltige Relay-URL: ${invalid.join(', ')}`, 'error');
    return false;
  }
  state.relays = Array.from(new Set(relays));
  if (nostr) {
    nostr.updateConfig({ defaultRelays: state.relays });
  }
  persistFormState();
  state.relayStatus.clear();
  state.relays.forEach((url) => updateRelayStatus(url, 'connecting'));
  return true;
}

function getManualBlogHex() {
  const npub = ui.blogPubkey.value.trim();
  if (!npub) {
    return null;
  }
  try {
    const { type, data } = nip19.decode(npub);
    if (type === 'npub' && typeof data === 'string') {
      return data;
    }
  } catch (error) {
    console.warn('Konnte Blog-npub nicht dekodieren', error);
  }
  return null;
}

async function handleSignRequest(withPublish) {
  if (!state.isLoggedIn) {
    showToast('Bitte zuerst anmelden, um die Signatur anzufordern.', 'error');
    return;
  }
  const relaysAreValid = updateRelaysFromInput();
  if (withPublish && !relaysAreValid) {
    return;
  }
  const content = ui.content.value.trim();
  if (!content) {
    showToast('Bitte einen Inhalt eingeben.', 'error');
    ui.content.focus();
    return;
  }
  const kind = Number(ui.eventKind.value);
  if (!Number.isInteger(kind) || kind < 0) {
    showToast('Kind muss eine nicht negative Zahl sein.', 'error');
    ui.eventKind.focus();
    return;
  }
  let tags;
  try {
    tags = collectTagRows(true);
  } catch (error) {
    showToast(error.message, 'error');
    return;
  }
  const eventPayload = {
    kind,
    created_at: Math.floor(Date.now() / 1000),
    tags,
    content
  };
  const signOptions = { broadcast: false };
  ui.publishButton.disabled = true;
  ui.signOnlyButton.disabled = true;
  ui.publishSummary.textContent = 'Event wird signiert ...';
  ui.publishResults.innerHTML = '';
  ui.publishStatus.hidden = false;
  try {
    const signedEvent = await nostr.signEvent(eventPayload, ui.signAs.value, signOptions);
    ui.publishSummary.textContent = 'Event erfolgreich signiert.';
    const eventJson = document.createElement('pre');
    eventJson.className = 'json-output';
    eventJson.textContent = JSON.stringify(signedEvent, null, 2);
    ui.publishResults.appendChild(eventJson);
    state.eventsMap.set(signedEvent.id, signedEvent);
    renderEvents();
    if (withPublish) {
      const relayResults = await publishEvent(signedEvent);
      renderPublishResults(relayResults);
    } else {
      renderPublishResults([]);
    }
    showToast('Event signiert', 'success');
  } catch (error) {
    ui.publishSummary.textContent = "Fehler: Event konnte nicht signiert werden.";
    ui.publishResults.innerHTML = '';
    renderPublishResults([]);
    showToast(error.message, 'error');
  } finally {
    ui.publishButton.disabled = !state.isLoggedIn;
    ui.signOnlyButton.disabled = !state.isLoggedIn;
  }
}

async function publishEvent(event) {
  if (!state.relays.length) {
    showToast('Kein Relay zum Veroeffentlichen ausgewaehlt.', 'error');
    return [];
  }
  const relays = Array.from(new Set(state.relays));
  relays.forEach((url) => updateRelayStatus(url, 'connecting'));
  try {
    const results = await nostr.publishEvent(event, relays, {
      timeout: 10000,
      onRelayStatus({ relay, status, message }) {
        if (status === 'ok') {
          updateRelayStatus(relay, 'ok', message || 'Veroeffentlichung bestaetigt');
        } else if (status === 'error') {
          updateRelayStatus(relay, 'error', message || 'Veroeffentlichung fehlgeschlagen');
        }
      }
    });
    return results.map((item) => ({
      relay: item.relay,
      ok: Boolean(item.ok),
      message: item.ok ? (item.message || 'OK') : undefined,
      reason: item.ok ? undefined : (item.reason || item.message || 'Veroeffentlichung fehlgeschlagen')
    }));
  } catch (error) {
    relays.forEach((url) => updateRelayStatus(url, 'error', error?.message || 'Veroeffentlichung fehlgeschlagen'));
    throw error;
  }
}
function renderPublishResults(results) {
  if (!results || !results.length) {
    const info = document.createElement('p');
    info.className = 'muted';
    info.textContent = 'Event wurde nicht an Relays gesendet.';
    ui.publishResults.appendChild(info);
    return;
  }
  ui.publishResults.innerHTML = '';
  let successCount = 0;
  results.forEach((result) => {
    const row = document.createElement('div');
    row.className = `publish-result ${result.ok ? 'ok' : 'error'}`;
    row.innerHTML = `<span>${result.relay}</span><span>${result.ok ? 'OK' : result.reason || 'Fehler'}</span>`;
    ui.publishResults.appendChild(row);
    if (result.ok) {
      successCount += 1;
    }
  });
  ui.publishSummary.textContent = `${successCount} von ${results.length} Relays haben das Event bestaetigt.`;
}

function clearEvents() {
  state.eventsMap.clear();
  renderEvents();
  showToast('Eventliste geleert', 'info');
}

function extendLimit() {
  state.limit += 50;
  ui.eventLimit.value = state.limit;
  persistFilterState();
  renderEvents();
  showToast(`Anzeigelimit auf ${state.limit} erhoeht.`, 'info');
}

function registerEventListeners() {
  ui.addTagButton.addEventListener('click', () => {
    addTagRow();
    persistTags();
  });
  ui.relayInput.addEventListener('blur', () => {
    if (updateRelaysFromInput()) {
      scheduleRefresh();
    }
  });
  ui.relayInput.addEventListener('change', updateRelaysFromInput);
  ui.eventKind.addEventListener('change', persistFormState);
  ui.content.addEventListener('input', () => storage.set(STORAGE_KEYS.content, ui.content.value));
  ui.signAs.addEventListener('change', persistFormState);
  ui.blogPubkey.addEventListener('change', () => {
    storage.set(STORAGE_KEYS.blogPubkey, ui.blogPubkey.value.trim());
    state.blogPubkey = getManualBlogHex();
  });
  ui.signOnlyButton.addEventListener('click', () => handleSignRequest(false));
  ui.publishButton.addEventListener('click', (event) => {
    event.preventDefault();
    handleSignRequest(true);
  });
  ui.refreshEvents.addEventListener('click', () => {
    if (updateRelaysFromInput()) {
      refreshEvents();
    }
  });
  ui.clearEvents.addEventListener('click', clearEvents);
  ui.loadMore.addEventListener('click', extendLimit);
  ui.filterKind.addEventListener('input', (event) => {
    const value = event.target.value;
    state.filter.kind = value;
    persistFilterState();
    scheduleRefresh();
  });
  ui.filterScope.forEach((input) => {
    input.addEventListener('change', (event) => {
      if (event.target.checked) {
        state.filter.scope = event.target.value;
        persistFilterState();
        refreshEvents();
      }
    });
  });
  ui.eventLimit.addEventListener('change', (event) => {
    const value = Number(event.target.value);
    if (Number.isInteger(value) && value >= 10) {
      state.limit = value;
      persistFilterState();
      renderEvents();
    }
  });
  window.addEventListener('beforeunload', () => {
    clearActiveSubscriptions();
    try {
      nostr?.close(state.relays);
    } catch (error) {
      // ignoriert
    }
  });
}

function init() {
  nostr = configureNostr({
    defaultRelays: cfg.defaultRelays,
    signUrl: cfg.signUrl,
    meUrl: cfg.meUrl,
    nonce: cfg.nonce,
    onRelayStatus({ relay, status, message }) {
      if (!relay) {
        return;
      }
      if (status === 'ok' || status === 'connect') {
        updateRelayStatus(relay, 'ok', message);
      } else if (status === 'disconnect') {
        updateRelayStatus(relay, 'offline', message || 'getrennt');
      } else if (status === 'error') {
        updateRelayStatus(relay, 'error', message || 'Fehler');
      }
    }
  });
  updateAuthControls();
  renderRelayStatus();
  loadStoredValues();
  renderTagRowsFromStorage();
  state.blogPubkey = getManualBlogHex();
  registerEventListeners();
  if (state.relays.length) {
    state.relays.forEach((url) => updateRelayStatus(url, 'connecting'));
    refreshEvents();
  }
  loadProfile();
}

document.addEventListener('DOMContentLoaded', init);('DOMContentLoaded', init);

































