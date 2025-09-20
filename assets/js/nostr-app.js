import { SimplePool } from 'https://esm.sh/nostr-tools@2.7.2/pool?target=es2022';

const DEFAULT_RELAYS = ['wss://relay.damus.io', 'wss://relay.snort.social'];
const DEFAULT_PUBLISH_TIMEOUT = 10000;
const DEFAULT_FETCH_TIMEOUT = 5000;

/**
 * Prueft eine uebergebene URL auf ein gueltiges ws/wss-Schema,
 * damit nur echte Relay-Adressen verwendet werden.
 */
function isValidRelayUrl(value) {
  return typeof value === 'string' && /^wss?:\/\//i.test(value.trim());
}

/**
 * Normalisiert eine Relaysammlung, entfernt Duplikate und faellt auf die
 * Standardliste zurueck, falls nichts angegeben ist.
 */
function sanitizeRelayList(relays, fallback) {
  const items = Array.isArray(relays) && relays.length ? relays : fallback;
  return Array.from(new Set((items || []).map((item) => item?.trim()).filter(isValidRelayUrl)));
}

/**
 * Raeumt einen eingehenden Event-Filter auf und entfernt ungueltige Werte,
 * bevor er an die nostr-tools Bibliothek gereicht wird.
 */
function sanitizeFilter(filter = {}) {
  const cleaned = {};
  if (Array.isArray(filter.kinds) && filter.kinds.length) {
    cleaned.kinds = filter.kinds
      .map((kind) => Number(kind))
      .filter((kind) => Number.isInteger(kind) && kind >= 0);
  }
  if (Array.isArray(filter.authors) && filter.authors.length) {
    cleaned.authors = filter.authors
      .map((author) => (typeof author === 'string' ? author.trim() : ''))
      .filter((author) => author.length > 0);
  }
  if (Array.isArray(filter.ids) && filter.ids.length) {
    cleaned.ids = filter.ids
      .map((id) => (typeof id === 'string' ? id.trim() : ''))
      .filter((id) => id.length > 0);
  }
  if (Array.isArray(filter['#e']) && filter['#e'].length) {
    cleaned['#e'] = filter['#e'].filter((tag) => typeof tag === 'string' && tag.trim().length > 0);
  }
  if (Array.isArray(filter['#p']) && filter['#p'].length) {
    cleaned['#p'] = filter['#p'].filter((tag) => typeof tag === 'string' && tag.trim().length > 0);
  }
  const limit = Number(filter.limit);
  if (Number.isInteger(limit) && limit > 0) {
    cleaned.limit = limit;
  }
  return cleaned;
}

/**
 * Kombiniert Benutzeroptionen mit den Standardwerten und liefert eine
 * konsistente Laufzeitkonfiguration fuer den Client zurueck.
 */
function buildConfig(options = {}) {
  return {
    defaultRelays: sanitizeRelayList(options.defaultRelays, DEFAULT_RELAYS),
    signUrl: options.signUrl || '/wp-json/nostr-signer/v1/sign-event',
    meUrl: options.meUrl || '/wp-json/nostr-signer/v1/me',
    nonce: options.nonce || null,
    credentials: options.credentials || 'include',
    publishTimeout: Number.isFinite(options.publishTimeout) ? Number(options.publishTimeout) : DEFAULT_PUBLISH_TIMEOUT,
    fetchTimeout: Number.isFinite(options.fetchTimeout) ? Number(options.fetchTimeout) : DEFAULT_FETCH_TIMEOUT,
    onRelayStatus: typeof options.onRelayStatus === 'function' ? options.onRelayStatus : null
  };
}

/**
 * Fuehrt einen REST-Aufruf gegen die WordPress-API aus und verarbeitet
 * JSON-Antworten inkl. Nonce-Handling sowie Fehlerableitung.
 */
async function requestJson(url, config, options = {}) {
  const headers = new Headers(options.headers || {});
  if (!headers.has('Content-Type') && options.body) {
    headers.set('Content-Type', 'application/json');
  }
  headers.set('Accept', 'application/json');
  if (config.nonce && !headers.has('X-WP-Nonce')) {
    headers.set('X-WP-Nonce', config.nonce);
  }
  const response = await fetch(url, {
    credentials: config.credentials,
    ...options,
    headers
  });
  let payload = null;
  try {
    payload = await response.json();
  } catch (error) {
    payload = null;
  }
  if (!response.ok) {
    const message = payload && (payload.message || payload.error)
      ? payload.message || payload.error
      : `Fehler ${response.status}`;
    throw new Error(message);
  }
  return payload ?? {};
}

/**
 * Erstellt einen isolierten Nostr-Client mit eigenem SimplePool,
 * Relay-Cache und konfigurierbaren REST-Endpunkten.
 */
export function createNostrClient(options = {}) {
  let config = buildConfig(options);
  const pool = new SimplePool();
  const relayCache = new Map();
  let activeSubscriptions = [];

  /**
   * Stellt sicher, dass ein Relay vorhanden und verbunden ist und meldet
   * Statusereignisse zurueck, sobald sich der Verbindungszustand aendert.
   */
  async function ensureRelay(url) {
    if (!isValidRelayUrl(url)) {
      throw new Error("Ungueltige Relay-URL: " + url);
    }
    const cached = relayCache.get(url);
    if (cached?.relay) {
      return cached.relay;
    }
    const relay = await pool.ensureRelay(url);
    const entry = relayCache.get(url) || { relay, listenersAttached: false };
    entry.relay = relay;
    if (!entry.listenersAttached && typeof relay.on === 'function') {
      entry.listenersAttached = true;
      relay.on('connect', () => config.onRelayStatus?.({ relay: url, status: 'connect' }));
      relay.on('disconnect', () => config.onRelayStatus?.({ relay: url, status: 'disconnect' }));
      relay.on('error', (err) => config.onRelayStatus?.({ relay: url, status: 'error', message: err?.message || 'Unbekannter Fehler' }));
    }
    relayCache.set(url, entry);
    return relay;
  }

  /**
   * Sendet ein signiertes Event an mehrere Relays und sammelt deren
   * Rueckmeldungen zu Erfolg und Fehlern.
   */
  async function publishEvent(event, relays, options = {}) {
    const relayList = sanitizeRelayList(relays, config.defaultRelays);
    if (!relayList.length) {
      throw new Error('Keine Relays angegeben.');
    }
    const timeout = Number.isFinite(options.timeout) ? Number(options.timeout) : config.publishTimeout;
    const onStatus = typeof options.onRelayStatus === 'function' ? options.onRelayStatus : config.onRelayStatus;
    const results = [];
    await Promise.all(relayList.map(async (relayUrl) => {
      try {
        await ensureRelay(relayUrl);
        const relay = relayCache.get(relayUrl)?.relay;
        if (!relay) {
          throw new Error('Relay konnte nicht initialisiert werden.');
        }
        const ackResult = await Promise.race([
          relay.publish(event).then((message) => ({
            ok: true,
            message: typeof message === 'string' && message.trim().length ? message : 'OK'
          })),
          new Promise((resolve) => setTimeout(() => resolve({
            ok: false,
            reason: "Timeout nach  Sekunden".replace(' Sekunden', ' ' + timeout / 1000 + ' Sekunden')
          }), timeout))
        ]);
        if (ackResult.ok) {
          onStatus?.({ relay: relayUrl, status: 'ok', message: ackResult.message });
          results.push({ relay: relayUrl, ok: true, message: ackResult.message });
        } else {
          const reason = ackResult.reason || 'Veroeffentlichung fehlgeschlagen';
          onStatus?.({ relay: relayUrl, status: 'error', message: reason });
          results.push({ relay: relayUrl, ok: false, reason });
        }
      } catch (error) {
        const message = error?.message || 'Verbindungsfehler';
        onStatus?.({ relay: relayUrl, status: 'error', message });
        results.push({ relay: relayUrl, ok: false, reason: message });
      }
    }));
    return results;
  }

  /**
   * Holt Events per Query von den angegebenen Relays und respektiert
   * definierte Zeitlimits und Filter.
   */
  async function fetchEvents(filter = {}, relays, options = {}) {
    const relayList = sanitizeRelayList(relays, config.defaultRelays);
    if (!relayList.length) {
      throw new Error('Keine Relays angegeben.');
    }
    const timeout = Number.isFinite(options.timeout) ? Number(options.timeout) : config.fetchTimeout;
    const relayFilter = sanitizeFilter({ ...filter, limit: filter.limit ?? options.limit });
    const events = await pool.querySync(relayList, relayFilter, { maxWait: timeout });
    return Array.isArray(events) ? events : [];
  }

  /**
   * Delegiert das Signieren eines Events an den REST-Endpunkt und gibt das
   * signierte Objekt zur weiteren Verarbeitung zurueck.
   */
  async function signEvent(eventData, keyType = 'user', extraPayload = {}) {
    const payload = {
      event: eventData,
      key_type: keyType,
      ...extraPayload
    };
    const response = await requestJson(config.signUrl, config, {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (!response?.event) {
      throw new Error('Antwort enthaelt kein signiertes Event.');
    }
    return response.event;
  }

  /**
   * Komforthelfer: kombiniert signEvent und publishEvent zu einem einzigen
   * Aufruf und liefert sowohl Event als auch Ergebnisliste.
   */
  async function send(eventData, keyType = 'user', relays, options = {}) {
    const signedEvent = await signEvent(eventData, keyType, options.signPayload || {});
    if (options.publish === false) {
      return { event: signedEvent, results: [] };
    }
    const results = await publishEvent(signedEvent, relays, options.publishOptions || {});
    return { event: signedEvent, results };
  }

  /**
   * Alternative Rueckgabe fuer fetchEvents, damit der Aufrufer neben dem
   * Event-Array auch erweiterbare Metadaten erhalten kann.
   */
  async function fetch(filter = {}, relays, options = {}) {
    const events = await fetchEvents(filter, relays, options.fetchOptions || options);
    return { events };
  }

  /**
   * Fragt den /me-Endpunkt ab, um Profildaten und Schluesselinformationen
   * fuer den aktuellen Benutzer zu erhalten.
   */
  async function getProfile() {
    return requestJson(config.meUrl, config);
  }

  /**
   * Stoppt alle laufenden Subscriptions und sorgt dafuer, dass keine
   * Ressourcenfresser im Hintergrund verbleiben.
   */
  function clearActiveSubscriptions() {
    activeSubscriptions.forEach((entry) => {
      try {
        if (entry?.sub?.close) {
          entry.sub.close();
        } else if (entry?.sub?.unsub) {
          entry.sub.unsub();
        }
      } catch (error) {
        // bewusst ignoriert
      }
    });
    activeSubscriptions = [];
  }

  /**
   * Richtet Live-Abonnements auf mehreren Relays ein und verbindet sie mit
   * Callback-Hooks fuer Events, EOSE, Fehler und Verbindungsabbrueche.
   */
  async function subscribe(filter, relays, callbacks = {}) {
    const relayList = sanitizeRelayList(relays, config.defaultRelays);
    if (!relayList.length) {
      throw new Error('Keine Relays angegeben.');
    }
    const relayFilter = sanitizeFilter(filter);
    const subscriptions = [];
    for (const relayUrl of relayList) {
      try {
        const relay = await ensureRelay(relayUrl);
        const sub = relay.subscribe([relayFilter], {
          onevent(event) {
            callbacks.onEvent?.(event, relayUrl);
          },
          oneose() {
            callbacks.onEose?.(relayUrl);
          },
          onerror(err) {
            callbacks.onError?.(err, relayUrl);
          },
          onclose(reason) {
            callbacks.onClose?.(reason, relayUrl);
          }
        });
        const entry = { relay: relay, relayUrl, sub };
        activeSubscriptions.push(entry);
        subscriptions.push(entry);
      } catch (error) {
        callbacks.onError?.(error, relayUrl);
      }
    }
    return () => {
      subscriptions.forEach((entry) => {
        try {
          if (entry?.sub?.close) {
            entry.sub.close();
          } else if (entry?.sub?.unsub) {
            entry.sub.unsub();
          }
        } catch (error) {
          // bewusst ignoriert
        }
      });
      activeSubscriptions = activeSubscriptions.filter((item) => !subscriptions.includes(item));
    };
  }

  /**
   * Schaltet den SimplePool fuer bestimmte Relays ab und leert danach den
   * Relay-Cache, um Speicher freizugeben.
   */
  function close(relays) {
    const relayList = sanitizeRelayList(relays, Array.from(relayCache.keys()));
    clearActiveSubscriptions();
    try {
      pool.close(relayList);
    } catch (error) {
      // bewusst ignoriert
    }
    relayCache.clear();
  }

  /**
   * Fuegt neue Optionen in die bestehende Konfiguration ein und stellt die
   * Konsistenz ueber buildConfig sicher.
   */
  function updateConfig(newOptions = {}) {
    config = buildConfig({ ...config, ...newOptions });
  }

  return {
    get config() {
      return { ...config };
    },
    updateConfig,
    ensureRelay,
    publishEvent,
    fetchEvents,
    signEvent,
    send,
    fetch,
    getProfile,
    subscribe,
    clearActiveSubscriptions,
    close
  };
}

// Global gemeinsam genutzte Client-Instanz zur Wiederverwendung
let sharedClient = null;

/**
 * Initialisiert die globale Client-Instanz neu und gibt sie fuer direkte
 * Verwendung im Frontend zurueck.
 */
export function configureNostr(config) {
  sharedClient = createNostrClient(config);
  return sharedClient;
}

/**
 * Sorgt dafuer, dass immer dieselbe Client-Instanz verwendet wird und
 * aktualisiert sie bei Bedarf mit neuen Optionen.
 */
function getSharedClient(config) {
  if (!sharedClient) {
    sharedClient = createNostrClient(config);
  } else if (config) {
    sharedClient.updateConfig(config);
  }
  return sharedClient;
}

/**
 * Short-Cut fuer signieren + veroeffentlichen unter Verwendung des
 * geteilten Clients.
 * Beispiel:
 * await nostr_send({ kind: 1, content: 'Hallo', tags: [], created_at: Math.floor(Date.now() / 1000) },
 *   'user', ['wss://relay.damus.io'], { publishOptions: { timeout: 8000 } });
 */
export async function nostr_send(eventData, keyType = 'user', relays, options = {}) {
  const client = getSharedClient(options.clientConfig);
  return client.send(eventData, keyType, relays, options);
}

/**
 * Short-Cut fuer das Laden von Events ueber den geteilten Client.
 * Beispiel:
 * const events = await nostr_fetch({ kinds: [1], authors: ['abc123'] },
 *   ['wss://relay.snort.social'], { fetchOptions: { timeout: 7000 } });
 */
export async function nostr_fetch(filter = {}, relays, options = {}) {
  const client = getSharedClient(options.clientConfig);
  const { events } = await client.fetch(filter, relays, options);
  return events;
}

/**
 * Short-Cut, um die Profilinformationen des angemeldeten Benutzers
 * ueber den geteilten Client abzurufen.
 * Beispiel:
 * const profile = await nostr_me({ clientConfig: { meUrl: '/wp-json/nostr-signer/v1/me' } });
 */
export async function nostr_me(options = {}) {
  const client = getSharedClient(options.clientConfig);
  return client.getProfile();
}

/**
 * Short-Cut fuer Live-Empfang: richtet eine Subscription auf den angegebenen
 * Relays ein und ruft den Callback fuer jedes passende Event auf.
 * Beispiel:
 * const stop = await nostr_onEvent(
 *   (event, relay) => console.log(`[${relay}]`, event.id),
 *   ['wss://relay.damus.io'],
 *   { kinds: [1] },
 *   { onEose: (relay) => console.log('EOSE von', relay) }
 * );
 * // spaeter: stop();
 */
export async function nostr_onEvent(callback, relays, filter = {}, options = {}) {
  if (typeof callback !== 'function') {
    throw new Error('Callback muss eine Funktion sein.');
  }
  const { clientConfig, onEose, onError, onClose } = options || {};
  const client = getSharedClient(clientConfig);
  const relayList = relays === undefined || relays === null
    ? undefined
    : Array.isArray(relays)
      ? relays
      : [relays];
  const filterObject = (filter && typeof filter === 'object') ? filter : {};
  const unsubscribe = await client.subscribe(filterObject, relayList, {
    onEvent(event, relayUrl) {
      callback(event, relayUrl);
    },
    onEose: typeof onEose === 'function' ? onEose : undefined,
    onError: typeof onError === 'function' ? onError : undefined,
    onClose: typeof onClose === 'function' ? onClose : undefined
  });
  return unsubscribe;
}

