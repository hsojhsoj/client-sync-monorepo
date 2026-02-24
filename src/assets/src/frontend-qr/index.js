/**
 * Frontend QR Code Renderer.
 *
 * Renders a QR code SVG inside the #clisyc-qr-code element on the
 * appointment detail page. Uses the `data-qr-payload` attribute as
 * the encoded content.
 *
 * @package ClientSync
 */
import qrcode from 'qrcode-generator';

document.addEventListener( 'DOMContentLoaded', () => {
	const el = document.getElementById( 'clisyc-qr-code' );
	if ( ! el ) {
		return;
	}

	const payload = el.dataset.qrPayload;
	if ( ! payload ) {
		return;
	}

	// Type 0 = auto-detect size, Error correction level M (15%).
	const qr = qrcode( 0, 'M' );
	qr.addData( payload );
	qr.make();

	el.innerHTML = qr.createSvgTag( {
		cellSize: 4,
		margin: 2,
		scalable: true,
	} );

	// Ensure the generated SVG fills the container.
	const svg = el.querySelector( 'svg' );
	if ( svg ) {
		svg.setAttribute( 'width', '100%' );
		svg.setAttribute( 'height', '100%' );
		svg.style.display = 'block';
	}
} );
