import { formatDuration, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

const MIME_CANDIDATES = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm', 'audio/ogg'];

const extensionFor = (mime) =>
    mime.includes('ogg') ? 'ogg' : mime.includes('mp4') ? 'm4a' : 'webm';

/**
 * Voice notes: record → stop → preview → send, plus the player used inside
 * message bubbles (one clip plays at a time).
 */
export class VoiceRecorder {
    constructor(chat) {
        this.chat = chat;
        this.maxSeconds = chat.config.limits.voice.max_seconds || 300;
        this.maxBytes = (chat.config.limits.voice.max_kb || 10240) * 1024;

        this.el = {
            record: document.querySelector('[data-voice-record]'),
            panel: document.querySelector('[data-voice-panel]'),
            recording: document.querySelector('[data-voice-recording]'),
            preview: document.querySelector('[data-voice-preview]'),
            timer: document.querySelector('[data-voice-timer]'),
            stop: document.querySelector('[data-voice-stop]'),
            send: document.querySelector('[data-voice-send]'),
            once: document.querySelector('[data-voice-once]'),
            cancel: document.querySelector('[data-voice-cancel]'),
        };

        this.state = 'idle'; // idle | recording | preview
        this.supported = Boolean(navigator.mediaDevices?.getUserMedia && window.MediaRecorder);

        if (!this.supported) this.el.record.hidden = true;

        this.el.record.addEventListener('click', () => this.start());
        this.el.stop.addEventListener('click', () => this.stop());
        this.el.cancel.addEventListener('click', () => this.discard());
        this.el.send.addEventListener('click', () => this.send());
        this.viewOnce = false;
        this.el.once?.addEventListener('click', () => {
            this.viewOnce = !this.viewOnce;
            this.el.once.classList.toggle('is-on', this.viewOnce);
            this.el.once.setAttribute('aria-pressed', String(this.viewOnce));
        });

        document.addEventListener('chat:opened', () => this.discard());
        document.addEventListener('chat:closed', () => this.discard());
    }

    async start() {
        if (!this.supported || this.state !== 'idle' || !this.chat.active) return;

        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
        } catch (error) {
            toast.error(
                error?.name === 'NotAllowedError'
                    ? 'Microphone access was denied. Allow it in your browser to record voice messages.'
                    : 'No microphone was found.',
            );
            return;
        }

        const mimeType = MIME_CANDIDATES.find((type) => MediaRecorder.isTypeSupported?.(type)) ?? '';
        this.recorder = new MediaRecorder(this.stream, mimeType ? { mimeType } : undefined);
        this.chunks = [];
        this.recorder.addEventListener('dataavailable', (event) => event.data.size && this.chunks.push(event.data));
        this.recorder.addEventListener('stop', () => this.onRecorderStop());

        this.recorder.start(250);
        this.startedAt = Date.now();
        this.setState('recording');
        this.chat.startRecordingIndicator?.();

        this.ticker = setInterval(() => {
            const seconds = (Date.now() - this.startedAt) / 1000;
            this.el.timer.textContent = formatDuration(seconds);
            if (seconds >= this.maxSeconds) {
                this.stop();
                toast.info(`Voice messages are limited to ${formatDuration(this.maxSeconds)}.`);
            }
        }, 200);
    }

    stop() {
        if (this.state !== 'recording') return;
        this.chat.stopRecordingIndicator?.();
        this.duration = (Date.now() - this.startedAt) / 1000;
        clearInterval(this.ticker);
        this.recorder.stop();
    }

    onRecorderStop() {
        this.releaseStream();
        if (this.discarding) {
            this.discarding = false;
            return;
        }

        const type = (this.recorder.mimeType || 'audio/webm').split(';')[0];
        this.blob = new Blob(this.chunks, { type });

        if (this.duration < 0.8 || this.blob.size === 0) {
            toast.info('Hold on a little longer to record a voice message.');
            this.reset();
            return;
        }

        if (this.blob.size > this.maxBytes) {
            toast.error('This voice message is too large to send.');
            this.reset();
            return;
        }

        this.previewUrl = URL.createObjectURL(this.blob);
        this.el.preview.innerHTML = html`
            <div class="voice-player is-preview" data-voice-player data-voice-src="${this.previewUrl}" data-duration="${this.duration}">
                <button type="button" class="voice-toggle" data-voice-toggle aria-label="Play preview">
                    ${raw(icon('play', 'voice-icon-play'))}${raw(icon('pause', 'voice-icon-pause'))}
                </button>
                <span class="voice-wave" data-voice-seek>${raw(bars())}<span class="voice-wave-progress" data-voice-progress>${raw(bars())}</span></span>
                <span class="voice-time" data-voice-time>${formatDuration(this.duration)}</span>
            </div>
        `;
        this.setState('preview');
    }

    send() {
        if (this.state !== 'preview' || !this.blob || !this.chat.active) return;
        const type = this.blob.type || 'audio/webm';
        const file = new File([this.blob], `voice-message.${extensionFor(type)}`, { type });

        this.chat.sendFile(this.chat.active.id, {
            file,
            type: 'voice',
            duration: Math.round(this.duration * 10) / 10,
            fileName: file.name,
            viewOnce: this.viewOnce,
        });
        this.reset();
    }

    discard() {
        if (this.state === 'recording') {
            this.discarding = true;
            clearInterval(this.ticker);
            this.recorder.stop();
        }
        this.reset();
    }

    reset() {
        this.chat.stopRecordingIndicator?.();
        stopAllVoice();
        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
        this.previewUrl = null;
        this.blob = null;
        this.chunks = [];
        this.el.preview.innerHTML = '';
        this.el.timer.textContent = '0:00';
        this.setState('idle');
    }

    releaseStream() {
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
    }

    setState(state) {
        this.state = state;
        const active = state !== 'idle';
        this.el.panel.hidden = !active;
        this.chat.el.composer.classList.toggle('is-recording', active);
        this.el.recording.hidden = state !== 'recording';
        this.el.stop.hidden = state !== 'recording';
        this.el.preview.hidden = state !== 'preview';
        this.el.send.hidden = state !== 'preview';
        if (this.el.once) {
            this.el.once.hidden = state !== 'preview';
            if (state === 'idle') {
                this.viewOnce = false;
                this.el.once.classList.remove('is-on');
                this.el.once.setAttribute('aria-pressed', 'false');
            }
        }
    }
}

function bars() {
    return Array.from({ length: 28 }, (_, i) => `<span style="height: ${25 + ((i * 53) % 65)}%"></span>`).join('');
}

/* ---------------------------------------------------------------------- */
/* Voice players inside message bubbles                                    */
/* ---------------------------------------------------------------------- */

let current = null; // { player, audio }

const SPEED_KEY = 'chat:voice-speed';
export const VOICE_SPEEDS = [1, 1.5, 2];

/** Playback speed for voice messages, remembered on this device. */
export function voiceSpeed() {
    try {
        const stored = Number(localStorage.getItem(SPEED_KEY));
        return VOICE_SPEEDS.includes(stored) ? stored : 1;
    } catch {
        return 1;
    }
}

export function nextVoiceSpeed(speed) {
    const index = VOICE_SPEEDS.indexOf(speed);
    return VOICE_SPEEDS[(index + 1) % VOICE_SPEEDS.length];
}

export function speedLabel(speed) {
    return `${speed}×`;
}

/**
 * The voice message to play after this one: the next message in the chat, when it is a
 * voice message from the same person (like WhatsApp playing consecutive voice notes).
 */
export function nextVoicePlayer(player) {
    const row = player.closest('[data-message-id]');
    if (!row) return null;

    let next = row.nextElementSibling;
    while (next && !next.matches('[data-message-id]')) next = next.nextElementSibling;

    if (!next || next.dataset.senderId !== row.dataset.senderId) return null;
    return next.querySelector('[data-voice-player]:not(.is-preview)');
}

function setSpeed(speed) {
    try {
        localStorage.setItem(SPEED_KEY, String(speed));
    } catch {
        /* storage unavailable: applies to this page only */
    }
    document.querySelectorAll('[data-voice-speed]').forEach((button) => (button.textContent = speedLabel(speed)));
    if (current) current.audio.playbackRate = speed;
}

function stopAllVoice() {
    if (current) {
        current.audio.pause();
        current.player.classList.remove('is-playing');
    }
    current = null;
}

function render(player, audio) {
    const duration = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : Number(player.dataset.duration) || 0;
    const ratio = duration ? Math.min(1, audio.currentTime / duration) : 0;
    player.querySelector('[data-voice-progress]').style.setProperty('--progress', `${ratio * 100}%`);
    player.querySelector('[data-voice-time]').textContent = formatDuration(audio.currentTime > 0 ? audio.currentTime : duration);
}

function audioFor(player) {
    if (player._audio) return player._audio;

    const audio = new Audio(player.dataset.voiceSrc);
    audio.preload = 'metadata';
    audio.addEventListener('timeupdate', () => render(player, audio));
    audio.addEventListener('ended', () => {
        player.classList.remove('is-playing');
        audio.currentTime = 0;
        render(player, audio);
        player.querySelector('[data-voice-time]').textContent = formatDuration(Number(player.dataset.duration) || audio.duration);
        if (current?.player === player) current = null;

        const next = player.classList.contains('is-preview') ? null : nextVoicePlayer(player);
        if (next) play(next);
    });
    audio.addEventListener('error', () => {
        player.classList.remove('is-playing');
        toast.error('This voice message could not be played.');
    });

    player._audio = audio;
    return audio;
}

export function bindVoicePlayers(root) {
    root.addEventListener('click', (event) => {
        const speed = event.target.closest('[data-voice-speed]');
        if (speed) {
            event.stopPropagation();
            setSpeed(nextVoiceSpeed(voiceSpeed()));
            return;
        }

        const toggle = event.target.closest('[data-voice-toggle]');
        const seek = event.target.closest('[data-voice-seek]');
        const player = (toggle || seek)?.closest('[data-voice-player]');
        if (!player) return;

        const audio = audioFor(player);

        if (seek) {
            const rect = seek.getBoundingClientRect();
            const duration = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : Number(player.dataset.duration) || 0;
            if (duration) audio.currentTime = Math.max(0, Math.min(duration, ((event.clientX - rect.left) / rect.width) * duration));
            render(player, audio);
            return;
        }

        if (current?.player === player && !audio.paused) {
            audio.pause();
            player.classList.remove('is-playing');
            return;
        }

        play(player);
    });
}

function play(player) {
    const audio = audioFor(player);
    stopAllVoice();
    audio.playbackRate = voiceSpeed();
    audio.play().then(() => {
        audio.playbackRate = voiceSpeed();
        player.classList.add('is-playing');
        current = { player, audio };
    }).catch(() => toast.error('This voice message could not be played.'));
}
