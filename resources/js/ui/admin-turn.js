import axios from '../bootstrap';
import { icon } from '../lib/icons';

/**
 * Admin → App settings → Call server (TURN): check from this browser that the TURN server gives
 * a relay (what a phone on mobile data needs), and copy the setup command.
 */

/** No relay and no error: the server is not running, or its ports are closed on the way. */
const NO_ANSWER = 'No answer from the TURN server: coturn is not running, or UDP 3478 / TCP 5349 are blocked by a firewall.';

const escape = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/** The relay address in a candidate line ("candidate:… 1 udp 41885439 203.0.113.5 49170 typ relay …"). */
export function relayAddress(candidate) {
    const parts = String(candidate ?? '').split(' ');
    const typ = parts.indexOf('typ');
    if (typ < 0 || parts[typ + 1] !== 'relay') return null;
    return `${parts[4]}:${parts[5]}`;
}

/**
 * Gather ICE candidates with relays only: a relay candidate means the TURN server works from here.
 *
 * @returns {Promise<{ ok: boolean, relays: string[], errors: string[] }>}
 */
export function testTurnServers(iceServers, { RTCPeerConnection = window.RTCPeerConnection, timeout = 10000 } = {}) {
    if (!RTCPeerConnection) return Promise.resolve({ ok: false, relays: [], errors: ['This browser cannot make calls (no WebRTC).'] });
    if (!iceServers?.length) return Promise.resolve({ ok: false, relays: [], errors: ['No TURN server is set up.'] });

    return new Promise((resolve) => {
        const relays = new Set();
        const errors = new Set();
        let pc;
        let timer = 0;

        const finish = () => {
            clearTimeout(timer);
            try {
                pc?.close();
            } catch {
                /* already closed */
            }
            resolve({ ok: relays.size > 0, relays: [...relays], errors: [...errors] });
        };

        try {
            pc = new RTCPeerConnection({ iceServers, iceTransportPolicy: 'relay' });
        } catch (error) {
            errors.add(error?.message || 'The TURN settings were not accepted.');
            finish();
            return;
        }

        pc.addEventListener('icecandidate', (event) => {
            if (!event.candidate) {
                if (!relays.size && !errors.size) errors.add(NO_ANSWER);
                finish();
                return;
            }
            const relay = relayAddress(event.candidate.candidate);
            if (relay) relays.add(relay);
        });
        pc.addEventListener('icecandidateerror', (event) => {
            const code = Number(event.errorCode);
            // Chrome first reports the server's normal password challenge (code 1): not a problem.
            if (!(code >= 300)) return;
            // 401: wrong secret; 701: could not reach the server.
            const reason = code === 401 ? 'the server did not accept the password (the secret here is not the one in coturn)' : code === 701 ? 'could not reach it' : event.errorText || `error ${code}`;
            errors.add(`${event.url || 'TURN'}: ${reason}`);
        });

        timer = setTimeout(() => {
            if (!relays.size && !errors.size) errors.add(NO_ANSWER);
            finish();
        }, timeout);
        pc.createDataChannel('turn-check');
        pc.createOffer()
            .then((offer) => pc.setLocalDescription(offer))
            .catch((error) => {
                errors.add(error?.message || 'Could not start the check.');
                finish();
            });
    });
}

export function initAdminTurn(root = document.getElementById('turn'), { http = axios, test = testTurnServers } = {}) {
    if (!root) return null;

    root.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const text = button.parentElement?.querySelector('[data-copy-source]')?.textContent?.trim() ?? '';
            try {
                await navigator.clipboard.writeText(text);
                button.innerHTML = `${icon('check')} Copied`;
            } catch {
                button.innerHTML = `${icon('circle-alert')} Select and copy`;
            }
        });
    });

    const button = root.querySelector('[data-turn-browser-test]');
    const output = root.querySelector('[data-turn-browser-result]');
    if (!button || !output) return { button: null };

    const show = (html, state) => {
        output.hidden = false;
        output.dataset.state = state;
        output.innerHTML = html;
    };

    const run = async () => {
        button.disabled = true;
        show(`${icon('loader-circle')} Asking the TURN server for a relay from this browser…`, 'busy');
        try {
            const { data } = await http.get(button.dataset.url);
            const result = await test(data.ice_servers ?? []);
            if (result.ok) {
                show(`${icon('circle-check')} <strong>Works from this network.</strong> Relay: ${result.relays.map(escape).join(', ')}`, 'ok');
            } else {
                const reasons = result.errors.length ? `<ul>${result.errors.map((error) => `<li>${escape(error)}</li>`).join('')}</ul>` : '';
                show(`${icon('circle-alert')} <strong>No relay from this network.</strong> Calls from here would fail when a direct connection is not possible.${reasons}`, 'bad');
            }
        } catch {
            show(`${icon('circle-alert')} The check could not start. Reload the page and try again.`, 'bad');
        } finally {
            button.disabled = false;
        }
    };

    button.addEventListener('click', run);
    return { button, run };
}
