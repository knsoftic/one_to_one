import axios from '../bootstrap';
import { toast } from '../lib/toast';

/**
 * Settings › Privacy › Ads (Y1). Ads themselves are not something the user switches — the only
 * choice here is the device's location permission, which is asked for by the phone (or the
 * browser) exactly as it is for contacts and the camera.
 */
export function initAdsSettings(root = document.querySelector('[data-ads-settings]')) {
    if (!root) return null;

    const route = root.dataset.routeProfile;
    const location = root.querySelector('[data-ads-location]');
    const list = root.querySelector('[data-ads-data]');

    const save = async (payload) => {
        const { data } = await axios.patch(route, payload);
        render(data.data ?? {});
        return data;
    };

    const render = (data) => {
        if (!list) return;
        const rows = Object.entries(data);
        list.innerHTML = rows.length
            ? rows.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(String(value))}</dd></div>`).join('')
            : '<p>Nothing yet.</p>';
    };

    location?.addEventListener('change', async () => {
        const wanted = location.checked;
        try {
            if (!wanted) {
                await save({ location_allowed: false });
                return;
            }
            // The system dialog — the same one contacts and the camera use.
            const position = await new Promise((resolve, reject) =>
                navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: false, timeout: 10000, maximumAge: 600_000 }),
            );
            await save({ location_allowed: true, lat: position.coords.latitude, lng: position.coords.longitude });
        } catch {
            location.checked = !wanted;
            toast(wanted ? 'Your device did not allow location.' : 'Could not save that. Try again.', { type: 'error' });
        }
    });

    return { save };
}

function escapeHtml(value) {
    return value.replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
}
