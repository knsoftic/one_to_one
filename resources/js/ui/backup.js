import axios from '../bootstrap';
import { errorMessage, formatBytes } from '../lib/dom';
import { toast } from '../lib/toast';

const when = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });
const day = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' });

/** What the "Last backup" row says. */
export function backupText(backup) {
    if (!backup) return 'No backup yet';
    switch (backup.status) {
        case 'pending':
        case 'working':
            return 'Making your backup… you can leave this page, it keeps going.';
        case 'failed':
            return `The backup didn't finish${backup.error ? `: ${backup.error}` : '.'} Try again.`;
        case 'expired':
            return `Made ${when.format(new Date(backup.finished_at ?? backup.created_at))} · no longer available`;
        default: {
            const parts = [
                when.format(new Date(backup.finished_at ?? backup.created_at)),
                formatBytes(backup.size),
                backup.include_media ? 'with media' : 'text only',
            ];
            if (backup.stats?.chats !== undefined) parts.push(`${backup.stats.chats} ${backup.stats.chats === 1 ? 'chat' : 'chats'}`);
            if (backup.expires_at) parts.push(`download until ${day.format(new Date(backup.expires_at))}`);
            return parts.join(' · ');
        }
    }
}

/**
 * D8 — Settings → Storage and data → Chat backup.
 */
export function initChatBackup(config, box = document.querySelector('[data-chat-backup]'), { pollMs = 4000 } = {}) {
    if (!box || !config?.routes?.backupShow) return null;

    let backup = null;
    try {
        backup = JSON.parse(box.dataset.backupCurrent || 'null');
    } catch {
        backup = null;
    }
    let timer = null;
    const available = box.dataset.backupAvailable !== '0';
    const text = box.querySelector('[data-backup-text]');
    const download = box.querySelector('[data-backup-download]');
    const start = box.querySelector('[data-backup-start]');
    const media = box.querySelector('[data-backup-media]');

    const busy = () => ['pending', 'working'].includes(backup?.status);

    const render = () => {
        text.textContent = available ? backupText(backup) : 'Backups are not available on this server.';
        download.hidden = !backup?.download_url;
        if (backup?.download_url) download.href = backup.download_url;
        start.disabled = !available || busy();
        start.classList.toggle('is-loading', busy());
    };

    const poll = () => {
        clearTimeout(timer);
        if (!busy()) return;
        timer = setTimeout(async () => {
            try {
                const { data } = await axios.get(config.routes.backupShow);
                const wasBusy = busy();
                backup = data.backup;
                render();
                if (wasBusy && backup?.status === 'ready') toast.success('Your backup is ready to download.');
                if (wasBusy && backup?.status === 'failed') toast.error("Your backup didn't finish. Try again.");
            } catch {
                /* try again on the next round */
            }
            poll();
        }, pollMs);
    };

    start.addEventListener('click', async () => {
        if (busy()) return;
        start.disabled = true;
        try {
            const { data } = await axios.post(config.routes.backupStore, { media: Boolean(media?.checked) });
            backup = data.backup;
            render();
            if (backup?.status === 'ready') toast.success('Your backup is ready to download.');
            poll();
        } catch (error) {
            toast.error(errorMessage(error, 'Could not start the backup.'));
            render();
        }
    });

    render();
    poll();

    return { get backup() { return backup; }, stop: () => clearTimeout(timer) };
}
