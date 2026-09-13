// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { distanceMetres, isLiveActive, mapsUrl, shouldSendUpdate } from '../location';
import { messageBubble, previewOf, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => 'Friend' });

describe('location sharing', () => {
    it('measures distances and builds map links', () => {
        // Lahore to Islamabad is roughly 270 km.
        expect(Math.round(distanceMetres({ lat: 31.5204, lng: 74.3587 }, { lat: 33.6844, lng: 73.0479 }) / 1000)).toBe(270);
        expect(mapsUrl(31.5204, 74.3587)).toBe('https://www.google.com/maps/search/?api=1&query=31.520400,74.358700');
    });

    it('throttles live updates by time and movement', () => {
        const last = { lat: 24.86, lng: 67.01, at: 0 };
        expect(shouldSendUpdate(null, last, 0)).toBe(true);
        expect(shouldSendUpdate(last, { lat: 24.87, lng: 67.01 }, 10_000)).toBe(false); // too soon
        expect(shouldSendUpdate(last, { lat: 24.8601, lng: 67.01 }, 20_000)).toBe(false); // about 11 m
        expect(shouldSendUpdate(last, { lat: 24.8603, lng: 67.01 }, 20_000)).toBe(true); // about 33 m
        expect(shouldSendUpdate(last, last, 61_000)).toBe(true); // periodic refresh
    });

    it('knows when a live location has ended', () => {
        const now = Date.parse('2026-09-13T12:00:00Z');
        expect(isLiveActive({ live: true, live_until: '2026-09-13T12:10:00Z' }, now)).toBe(true);
        expect(isLiveActive({ live: true, live_until: '2026-09-13T11:59:00Z' }, now)).toBe(false);
        expect(isLiveActive({ live: true, live_until: '2026-09-13T12:10:00Z', stopped_at: '2026-09-13T11:58:00Z' }, now)).toBe(false);
        expect(isLiveActive({ live: false }, now)).toBe(false);
    });

    it('renders a location card with a stop button only for my active live location', () => {
        const until = new Date(Date.now() + 600_000).toISOString();
        const base = { id: 5, status: 'seen', created_at: new Date().toISOString(), type: 'location' };
        const host = document.createElement('div');

        host.innerHTML = messageBubble({ ...base, sender_id: 1, is_mine: true, location: { lat: 31.52, lng: 74.35, live: true, live_active: true, live_until: until } });
        expect(host.querySelector('.location-title').textContent).toBe('Live location');
        expect(host.querySelector('[data-location-stop="5"]')).not.toBeNull();
        expect(host.querySelector('.location-open').getAttribute('href')).toContain('query=31.520000,74.350000');

        host.innerHTML = messageBubble({ ...base, sender_id: 2, is_mine: false, location: { lat: 31.52, lng: 74.35, live: true, live_active: true, live_until: until } });
        expect(host.querySelector('[data-location-stop]')).toBeNull();

        host.innerHTML = messageBubble({ ...base, sender_id: 1, is_mine: true, location: { lat: 1, lng: 2, live: true, live_active: false, live_until: until, stopped_at: until } });
        expect(host.querySelector('.location-title').textContent).toBe('Live location ended');

        expect(previewOf({ type: 'location', location: { live: false } })).toBe('📍 Location');
    });
});
