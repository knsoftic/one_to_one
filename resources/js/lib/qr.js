import qrcode from 'qrcode-generator';

/** QR code of a text as an SVG (scales to its box, dark modules on a light ground). */
export function qrSvg(text) {
    const qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
}
