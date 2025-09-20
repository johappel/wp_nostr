// Kleiner Wrapper für nostr-tools — sichert die API für das Frontend
import { relayInit, getPublicKey, getEventHash, signEvent, nip19 } from 'nostr-tools';

export { relayInit, getPublicKey, getEventHash, signEvent, nip19 };

export async function publishToRelays(relays, event) {
  if (!Array.isArray(relays)) relays = [relays];
  const results = [];
  for (const url of relays) {
    try {
      const relay = relayInit(url);
      await relay.connect();
      const pub = relay.publish(event);
      const res = await new Promise((resolve) => {
        pub.on('ok', () => resolve({ url, ok: true }));
        pub.on('failed', (reason) => resolve({ url, ok: false, reason }));
      });
      relay.close();
      results.push(res);
    } catch (e) {
      results.push({ url, ok: false, error: String(e) });
    }
  }
  return results;
}
