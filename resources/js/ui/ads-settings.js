import axios from '../bootstrap';
import { toast } from '../lib/toast';

/**
 * Settings → Privacy → Ads (Y1): the personalised-ads switch, the optional coarse-location toggle,
 * gender and birth year, and the "ad data we keep about you" list. Turning the switch off deletes
 * the ad profile on the server.
 */
export function initAdsSettings(root = document.querySelector('[data-ads-settings]')) {
    if (!root) return null;

    const consentUrl = root.dataset.routeConsent;
    const profileUrl = root.dataset.routeProfile;
    const details = root.querySelector('[data-ads-details]');
    const toggle = root.querySelector('[data-ads-personalised]');
    const location = root.querySelector('[data-ads-location]');
    const gender = root.querySelector('[data-ads-gender]');
    const birthYear = root.querySelector('[data-ads-birth-year]');

    const deviceContext = () => {
        let timezone = '';
        try {
            timezone = Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
        } catch {
            /* older browsers */
        }
        return { timezone, locale: (navigator.language || '').slice(0, 12), platform: window.Capacitor?.isNativePlatform?.() ? 'android' : 'web' };
    };

    const renderData = (data = {}) => {
        const dl = root.querySelector('[data-ads-data]');
        if (!dl) return;
        const entries = Object.entries(data);
        dl.innerHTML = entries.length
            ? entries.map(([k, v]) => `<div><dt>${escapeHtml(k)}</dt><dd>${escapeHtml(String(v))}</dd></div>`).join('')
            : '<p>Nothing yet.</p>';
    };

    // Personalised ads on/off.
    toggle?.addEventListener('change', async () => {
        const on = toggle.checked;
        details.hidden = !on;
        try {
            const { data } = await axios.post(consentUrl, { personalised: on, ...deviceContext() });
            renderData(data.data);
            toast(on ? 'Personalised ads turned on.' : 'Personalised ads turned off and your ad data deleted.');
        } catch {
            toggle.checked = !on;
            details.hidden = on;
            toast('Could not save. Try again.', 'error');
        }
    });

    const patch = async (payload) => {
        try {
            const { data } = await axios.patch(profileUrl, payload);
            renderData(data.data);
            if (location) location.checked = data.location_allowed;
        } catch {
            toast('Could not save. Try again.', 'error');
        }
    };

    // Coarse location: ask the browser only when turning it on.
    location?.addEventListener('change', () => {
        if (!location.checked) {
            patch({ location_allowed: false });
            return;
        }
        if (!navigator.geolocation) {
            location.checked = false;
            toast('This device cannot share a location.', 'error');
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => patch({ location_allowed: true, lat: pos.coords.latitude, lng: pos.coords.longitude, timezone: deviceContext().timezone }),
            () => {
                location.checked = false;
                toast('Location permission was not given.', 'error');
            },
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 600000 },
        );
    });

    gender?.addEventListener('change', () => patch({ gender: gender.value || null }));
    birthYear?.addEventListener('change', () => {
        const year = parseInt(birthYear.value, 10);
        patch({ birth_year: Number.isFinite(year) ? year : null });
    });

    return { renderData };
}

function escapeHtml(text) {
    return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}
