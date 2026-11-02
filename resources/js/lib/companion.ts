import { http } from '@inertiajs/react';
import type { RouteDefinition } from '@/wayfinder';

/**
 * The longest edge Claude reads images at without downscaling them itself.
 */
const MAX_IMAGE_DIMENSION = 1568;

export class RequestError extends Error {
    status: number | null;

    constructor(message: string, status: number | null) {
        super(message);
        this.status = status;
    }
}

/**
 * Send a JSON request to one of the chat endpoints and parse the response.
 */
export async function sendRequest<TResponse>(
    route: RouteDefinition<'post'>,
    data: FormData | Record<string, unknown>,
): Promise<TResponse> {
    try {
        const response = await http.getClient().request({
            method: route.method,
            url: route.url,
            data,
            headers: { Accept: 'application/json' },
        });

        return JSON.parse(response.data) as TResponse;
    } catch (error) {
        throw toRequestError(error);
    }
}

function toRequestError(error: unknown): RequestError {
    const response =
        error && typeof error === 'object' && 'response' in error
            ? (error.response as { status: number; data: string })
            : null;

    if (!response) {
        return new RequestError('Connection lost. Try again.', null);
    }

    try {
        const payload = JSON.parse(response.data) as { message?: string };

        return new RequestError(
            payload.message ?? 'Something went wrong.',
            response.status,
        );
    } catch {
        return new RequestError('Something went wrong.', response.status);
    }
}

export function currentTimezone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

/**
 * Shrink a photo to the size Claude reads best and re-encode it as JPEG.
 */
export async function downscaleImage(file: File): Promise<File> {
    try {
        const bitmap = await createImageBitmap(file);
        const canvas = drawScaled(bitmap, bitmap.width, bitmap.height);
        bitmap.close();

        return (await canvasToJpeg(canvas)) ?? file;
    } catch {
        return file;
    }
}

/**
 * Grab the current frame of a live video as a JPEG photo.
 */
export async function captureVideoFrame(
    video: HTMLVideoElement,
): Promise<File | null> {
    if (video.videoWidth === 0) {
        return null;
    }

    return canvasToJpeg(drawScaled(video, video.videoWidth, video.videoHeight));
}

function drawScaled(
    source: CanvasImageSource,
    width: number,
    height: number,
): HTMLCanvasElement {
    const scale = Math.min(1, MAX_IMAGE_DIMENSION / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);
    canvas
        .getContext('2d')
        ?.drawImage(source, 0, 0, canvas.width, canvas.height);

    return canvas;
}

function canvasToJpeg(canvas: HTMLCanvasElement): Promise<File | null> {
    return new Promise((resolve) => {
        canvas.toBlob(
            (blob) =>
                resolve(
                    blob
                        ? new File([blob], 'photo.jpg', { type: 'image/jpeg' })
                        : null,
                ),
            'image/jpeg',
            0.85,
        );
    });
}
