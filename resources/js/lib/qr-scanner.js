/**
 * Camera QR scanning shared by "Link a device" (P10) and "Scan code" (A4).
 * Uses the browser's BarcodeDetector; devices without it type or paste instead.
 */
export function canScanQr() {
    return typeof window !== 'undefined' && 'BarcodeDetector' in window && Boolean(navigator.mediaDevices?.getUserMedia);
}

/**
 * Show the back camera in `video` and pass the texts of the QR codes it sees to
 * `onCodes` a few times a second, until `onCodes` returns true or the returned
 * stop() is called. Rejects when the camera can't be used.
 */
export async function startQrCamera(video, onCodes, { interval = 250 } = {}) {
    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
    let stopped = false;
    let timer = null;
    const stop = () => {
        stopped = true;
        clearTimeout(timer);
        stream.getTracks().forEach((track) => track.stop());
    };

    try {
        video.srcObject = stream;
        await video.play();
    } catch (error) {
        stop();
        throw error;
    }

    const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
    const tick = async () => {
        if (stopped) return;
        try {
            const codes = await detector.detect(video);
            if (codes.length && (await onCodes(codes.map((code) => code.rawValue)))) {
                stop();
                return;
            }
        } catch {
            /* keep scanning */
        }
        if (!stopped) timer = setTimeout(tick, interval);
    };
    tick();

    return stop;
}
