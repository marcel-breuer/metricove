export const receiptPageLimit = 5;
export const receiptFileSizeLimit = 10 * 1024 * 1024;
const sourcePageSizeLimit = 5 * 1024 * 1024;
const sourceBatchSizeLimit = 25 * 1024 * 1024;
const maxImageDimension = 2200;
const a4Width = 595.28;
const a4Height = 841.89;
const pageMargin = 20;

function isHeic(file: File): boolean {
	return /\.(?:heic|heif)$/i.test(file.name) || ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'].includes(file.type.toLowerCase());
}

function loadImage(blob: Blob): Promise<HTMLImageElement> {
	return new Promise((resolve, reject) => {
		const url = URL.createObjectURL(blob);
		const image = new Image();
		image.onload = () => {
			URL.revokeObjectURL(url);
			resolve(image);
		};
		image.onerror = () => {
			URL.revokeObjectURL(url);
			reject(new Error('This photo could not be decoded. Choose a JPEG, PNG or HEIC image.'));
		};
		image.src = url;
	});
}

function canvasJpeg(canvas: HTMLCanvasElement): Promise<Blob> {
	return new Promise((resolve, reject) => {
		canvas.toBlob((blob) => {
			if (blob) resolve(blob);
			else reject(new Error('This photo could not be prepared for the receipt.'));
		}, 'image/jpeg', 0.82);
	});
}

async function asJpeg(file: File): Promise<Blob> {
	let source: Blob = file;
	if (isHeic(file)) {
		try {
			const { default: heic2any } = await import('heic2any');
			const converted = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.88 });
			if (Array.isArray(converted)) throw new Error('Choose a single HEIC photo per page.');
			source = converted;
		} catch (cause) {
			if (cause instanceof Error && cause.message.startsWith('Choose a single')) throw cause;
			throw new Error('This HEIC photo could not be converted on this device. Save it as JPEG or choose another photo.');
		}
	} else if (file.type !== 'image/jpeg' && file.type !== 'image/png') {
		throw new Error('Choose a PDF, JPEG, PNG or HEIC file.');
	}

	const image = await loadImage(source);
	if (image.naturalWidth < 1 || image.naturalHeight < 1 || image.naturalWidth > 6000 || image.naturalHeight > 6000) {
		throw new Error('Each photo must be no larger than 6,000 by 6,000 pixels.');
	}
	const scale = Math.min(1, maxImageDimension / Math.max(image.naturalWidth, image.naturalHeight));
	const canvas = document.createElement('canvas');
	canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
	canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
	const context = canvas.getContext('2d');
	if (!context) throw new Error('Photo processing is unavailable in this browser.');
	context.fillStyle = '#fff';
	context.fillRect(0, 0, canvas.width, canvas.height);
	context.drawImage(image, 0, 0, canvas.width, canvas.height);
	return canvasJpeg(canvas);
}

export async function createReceiptPdf(images: File[]): Promise<File> {
	const { PDFDocument } = await import('pdf-lib');
	if (images.length < 1 || images.length > receiptPageLimit) {
		throw new Error(`Choose between 1 and ${receiptPageLimit} photo pages.`);
	}
	if (images.some((image) => image.size > sourcePageSizeLimit) || images.reduce((total, image) => total + image.size, 0) > sourceBatchSizeLimit) {
		throw new Error('Photo files must be 5 MB or smaller each and 25 MB combined.');
	}

	const pdf = await PDFDocument.create();
	for (const file of images) {
		const jpeg = await asJpeg(file);
		const bytes = new Uint8Array(await jpeg.arrayBuffer());
		const embedded = await pdf.embedJpg(bytes);
		const page = pdf.addPage([a4Width, a4Height]);
		const scale = Math.min(
			(a4Width - pageMargin * 2) / embedded.width,
			(a4Height - pageMargin * 2) / embedded.height,
		);
		const width = embedded.width * scale;
		const height = embedded.height * scale;
		page.drawImage(embedded, {
			x: (a4Width - width) / 2,
			y: (a4Height - height) / 2,
			width,
			height,
		});
	}

	const bytes = await pdf.save();
	if (bytes.byteLength > receiptFileSizeLimit) {
		throw new Error('The combined scan is larger than 10 MB. Remove a page or use a smaller photo.');
	}

	return new File([bytes.slice().buffer as ArrayBuffer], `receipt-scan-${new Date().toISOString().slice(0, 10)}.pdf`, { type: 'application/pdf' });
}
