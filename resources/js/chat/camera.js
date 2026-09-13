import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * M15 — Take a photo or record a video inside the app (also in the Android
 * app's WebView, which already has camera and microphone permission for calls).
 * The captured file goes to the attachment tray, where it can be edited,
 * captioned and sent.
 */

const VIDEO_BITS_PER_SECOND = 1_500_000;
const AUDIO_BITS_PER_SECOND = 96_000;

/**
 * Recording format: WebM where available (Chrome, Android, Firefox), MP4 on Safari.
 * Chromium reports MP4 as supported but can produce empty files at camera
 * resolutions without a hardware H.264 encoder, so WebM comes first.
 */
export function recordingFormat(isTypeSupported = (type) => globalThis.MediaRecorder?.isTypeSupported?.(type)) {
    const candidates = [
        ['video/webm;codecs=vp9,opus', 'webm'],
        ['video/webm;codecs=vp8,opus', 'webm'],
        ['video/webm', 'webm'],
        ['video/mp4', 'mp4'],
    ];

    for (const [mimeType, extension] of candidates) {
        if (isTypeSupported(mimeType)) return { mimeType, extension, type: mimeType.split(';')[0] };
    }

    return null;
}

/** Longest recording that stays under the upload limit (10% headroom), capped at 5 minutes. */
export function maxRecordingSeconds(maxBytes) {
    const seconds = Math.floor(((maxBytes * 8) / (VIDEO_BITS_PER_SECOND + AUDIO_BITS_PER_SECOND)) * 0.9);
    return Math.max(5, Math.min(300, seconds));
}

/** File name like WhatsApp's: IMG_20260913_142501.jpg */
export function captureName(prefix, extension, date = new Date()) {
    const pad = (n) => String(n).padStart(2, '0');
    const stamp = `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}_${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}`;
    return `${prefix}_${stamp}.${extension}`;
}

export function cameraSupported() {
    return Boolean(navigator.mediaDevices?.getUserMedia);
}

/**
 * @param {{maxVideoBytes: number, onCapture: (file: File) => void}} options
 */
export function openCamera(options) {
    if (!cameraSupported()) {
        toast.error('This browser cannot use the camera.');
        return null;
    }

    const camera = new CameraCapture(options);
    camera.open();
    return camera;
}

class CameraCapture {
    constructor({ maxVideoBytes, onCapture }) {
        this.onCapture = onCapture;
        this.maxSeconds = maxRecordingSeconds(maxVideoBytes);
        this.format = recordingFormat();
        this.facing = 'environment';
        this.mode = 'photo';
        this.stream = null;
        this.audio = null;
        this.recorder = null;
        this.previouslyFocused = document.activeElement;
        this.onKey = (event) => {
            if (event.key === 'Escape') this.close();
        };
    }

    open() {
        this.overlay = document.createElement('div');
        this.overlay.className = 'camera';
        this.overlay.dataset.mode = 'photo';
        this.overlay.setAttribute('role', 'dialog');
        this.overlay.setAttribute('aria-modal', 'true');
        this.overlay.setAttribute('aria-label', 'Camera');
        this.overlay.innerHTML = html`
            <video class="camera-preview" data-camera-preview autoplay playsinline muted></video>
            <div class="camera-message" data-camera-message hidden></div>
            <div class="camera-top">
                <button type="button" class="camera-btn" data-camera-close aria-label="Close camera">${raw(icon('x'))}</button>
                <span class="camera-timer" data-camera-timer hidden>0:00</span>
                <button type="button" class="camera-btn" data-camera-flip aria-label="Switch camera" hidden>${raw(icon('switch-camera'))}</button>
            </div>
            <div class="camera-bottom">
                <div class="camera-modes" role="tablist" aria-label="Camera mode">
                    <button type="button" class="camera-mode is-active" data-camera-mode="photo" role="tab" aria-selected="true">Photo</button>
                    ${raw(this.format ? '<button type="button" class="camera-mode" data-camera-mode="video" role="tab" aria-selected="false">Video</button>' : '')}
                </div>
                <button type="button" class="camera-shutter" data-camera-shutter aria-label="Take photo"><span></span></button>
                <span class="camera-limit" data-camera-limit></span>
            </div>
        `;

        this.preview = this.overlay.querySelector('[data-camera-preview]');
        this.overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-camera-close]')) this.close();
            else if (event.target.closest('[data-camera-flip]')) this.flip();
            else if (event.target.closest('[data-camera-shutter]')) this.shutter();
            else if (event.target.closest('[data-camera-mode]')) this.setMode(event.target.closest('[data-camera-mode]').dataset.cameraMode);
        });

        document.body.appendChild(this.overlay);
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', this.onKey);
        this.overlay.querySelector('[data-camera-shutter]').focus();
        this.start();
    }

    async start() {
        this.stopTracks(this.stream);
        this.stream = null;

        try {
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: this.facing }, width: { ideal: 1920 }, height: { ideal: 1080 } },
                audio: false,
            });
        } catch (error) {
            const denied = ['NotAllowedError', 'SecurityError'].includes(error?.name);
            this.showMessage(denied
                ? 'Camera access is blocked. Allow the camera for this site (or in the app settings) and try again.'
                : 'No camera was found on this device.');
            return;
        }

        if (!this.overlay.isConnected) {
            this.stopTracks(this.stream);
            return;
        }

        this.preview.srcObject = this.stream;
        const settings = this.stream.getVideoTracks()[0]?.getSettings?.() ?? {};
        this.mirrored = settings.facingMode === 'user' || (this.facing === 'user' && !settings.facingMode);
        this.preview.classList.toggle('is-mirrored', this.mirrored);
        this.showMessage(null);

        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            this.overlay.querySelector('[data-camera-flip]').hidden = devices.filter((d) => d.kind === 'videoinput').length < 2;
        } catch {
            /* keep the button hidden */
        }
    }

    showMessage(text) {
        const box = this.overlay.querySelector('[data-camera-message]');
        box.hidden = !text;
        box.textContent = text ?? '';
    }

    flip() {
        if (this.recorder) return;
        this.facing = this.facing === 'user' ? 'environment' : 'user';
        this.start();
    }

    setMode(mode) {
        if (this.recorder || mode === this.mode) return;
        this.mode = mode;
        this.overlay.dataset.mode = mode;

        for (const button of this.overlay.querySelectorAll('[data-camera-mode]')) {
            const active = button.dataset.cameraMode === mode;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
        }

        this.overlay.querySelector('[data-camera-shutter]').setAttribute('aria-label', mode === 'video' ? 'Start recording' : 'Take photo');
        this.overlay.querySelector('[data-camera-limit]').textContent = mode === 'video' ? `Up to ${formatSeconds(this.maxSeconds)}` : '';
    }

    shutter() {
        if (!this.stream) return;
        if (this.mode === 'photo') this.takePhoto();
        else if (this.recorder) this.recorder.stop();
        else this.startRecording();
    }

    takePhoto() {
        const { videoWidth: width, videoHeight: height } = this.preview;
        if (!width || !height) return;

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        // Selfies are saved the way they were seen on screen.
        if (this.mirrored) {
            ctx.translate(width, 0);
            ctx.scale(-1, 1);
        }
        ctx.drawImage(this.preview, 0, 0, width, height);

        this.overlay.classList.add('is-flash');
        canvas.toBlob((blob) => {
            if (!blob) {
                toast.error('The photo could not be taken.');
                return;
            }
            this.finish(new File([blob], captureName('IMG', 'jpg'), { type: 'image/jpeg', lastModified: Date.now() }));
        }, 'image/jpeg', 0.9);
    }

    async startRecording() {
        const tracks = [...this.stream.getVideoTracks()];

        try {
            this.audio = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
            tracks.push(...this.audio.getAudioTracks());
        } catch {
            toast.warning('Recording without sound: the microphone is not available.');
        }

        const chunks = [];
        try {
            this.recorder = new MediaRecorder(new MediaStream(tracks), {
                mimeType: this.format.mimeType,
                videoBitsPerSecond: VIDEO_BITS_PER_SECOND,
                audioBitsPerSecond: AUDIO_BITS_PER_SECOND,
            });
        } catch {
            this.stopTracks(this.audio);
            toast.error('This browser cannot record video.');
            return;
        }

        const started = Date.now();
        const timer = this.overlay.querySelector('[data-camera-timer]');
        timer.hidden = false;
        this.overlay.classList.add('is-recording');
        this.overlay.querySelector('[data-camera-shutter]').setAttribute('aria-label', 'Stop recording');

        this.tick = setInterval(() => {
            const elapsed = (Date.now() - started) / 1000;
            timer.textContent = formatSeconds(elapsed);
            if (elapsed >= this.maxSeconds) this.recorder?.stop();
        }, 250);

        this.recorder.addEventListener('dataavailable', (event) => {
            if (event.data?.size) chunks.push(event.data);
        });
        this.recorder.addEventListener('stop', () => {
            clearInterval(this.tick);
            this.stopTracks(this.audio);
            this.audio = null;
            this.recorder = null;
            timer.hidden = true;
            this.overlay.classList.remove('is-recording');

            if (!this.overlay.isConnected || this.discard) return;

            const blob = new Blob(chunks, { type: this.format.type });
            if (blob.size < 1024) {
                toast.error(blob.size ? 'The video was too short.' : 'The video could not be recorded on this device.');
                return;
            }
            this.finish(new File([blob], captureName('VID', this.format.extension), { type: this.format.type, lastModified: Date.now() }));
        });

        this.recorder.start(1000);
    }

    finish(file) {
        this.onCapture(file);
        this.close();
    }

    close() {
        if (this.recorder) {
            this.discard = true;
            this.recorder.stop();
        }
        clearInterval(this.tick);
        this.stopTracks(this.stream);
        this.stopTracks(this.audio);
        this.stream = null;
        document.removeEventListener('keydown', this.onKey);
        this.overlay.remove();
        document.body.style.overflow = '';
        this.previouslyFocused?.focus?.();
    }

    stopTracks(stream) {
        stream?.getTracks().forEach((track) => track.stop());
    }
}

function formatSeconds(value) {
    const total = Math.floor(value);
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}
