import { Sha256 } from './sha256.js';
/** The server rejected the upload (validation, authorization, verification) or it failed on the way. */
export class UploadError extends Error {
    messages;
    fromServer;
    /**
     * @param messages      why the upload failed, in English unless the server sent (translated) messages
     * @param fromServer    whether the messages come from the server's response
     */
    constructor(messages, fromServer = false) {
        super(messages.join(' '));
        this.messages = messages;
        this.fromServer = fromServer;
        this.name = 'UploadError';
    }
}
/**
 * Resolves with the upload id to submit with the form. Rejects with an UploadError,
 * or a DOMException named "AbortError" when the signal aborts.
 */
export async function upload(file, options) {
    const descriptor = {
        filename: file.name,
        size: file.size,
        mimeType: file.type || 'application/octet-stream',
    };
    if (options.checksum) {
        descriptor.sha256 = await sha256(file);
    }
    const presign = await presignUpload(descriptor, options);
    const status = await put(presign, file, options);
    // 412: a retried PUT whose first response got lost, the object exists already. The server verifies it.
    if ((status < 200 || status >= 300) && status !== 412) {
        throw new UploadError([`The upload failed (HTTP ${status}).`]);
    }
    const verifyUrl = presign.verifyUrl;
    if (typeof options.verify === 'function') {
        await verify(options.verify, presign.uploadId);
    }
    else if ((options.verify ?? true) && verifyUrl !== undefined) {
        await verify(() => verifyOverHttp(verifyUrl, options), presign.uploadId);
    }
    return presign.uploadId;
}
async function presignUpload(descriptor, options) {
    if (options.presign) {
        return options.presign(descriptor);
    }
    if (options.presignUrl === undefined) {
        throw new TypeError('Either "presignUrl" or "presign" is required.');
    }
    const response = await postJson(options.presignUrl, descriptor, options);
    if (!response.ok) {
        throw await serverError(response, `The upload could not be started (HTTP ${response.status}).`);
    }
    return (await response.json());
}
/**
 * Fails only on a definitive answer: a rejected or expired upload, or an UploadError (e.g. an unknown upload).
 * Anything else (a network error, a server error) leaves the decision to the claim, which verifies again.
 */
async function verify(verifyFn, uploadId) {
    let result;
    try {
        result = await verifyFn(uploadId);
    }
    catch (error) {
        if (error instanceof UploadError || (error instanceof DOMException && error.name === 'AbortError')) {
            throw error;
        }
        return;
    }
    if (result.state === 'rejected' || result.state === 'expired') {
        const messages = result.violations.map((violation) => violation.message);
        throw messages.length > 0 ? new UploadError(messages, true) : new UploadError(['The uploaded file could not be verified.']);
    }
}
async function verifyOverHttp(url, options) {
    const response = await postJson(url, {}, options);
    if (response.status === 404) {
        throw await serverError(response, 'The uploaded file could not be verified.');
    }
    if (![200, 409, 410, 422].includes(response.status)) {
        throw new Error(`Verification failed (HTTP ${response.status}).`);
    }
    return (await response.json());
}
/** Files up to this size are hashed in one go with WebCrypto, larger ones chunk by chunk. */
const HASH_CHUNK = 8 * 1024 * 1024;
/**
 * Base64 SHA-256 of a file, without loading large files into memory at once.
 */
export async function sha256(file) {
    let digest;
    if (file.size <= HASH_CHUNK) {
        digest = new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer()));
    }
    else {
        const hash = new Sha256();
        for (let offset = 0; offset < file.size; offset += HASH_CHUNK) {
            hash.update(new Uint8Array(await file.slice(offset, offset + HASH_CHUNK).arrayBuffer()));
        }
        digest = hash.digest();
    }
    let binary = '';
    for (const byte of digest) {
        binary += String.fromCharCode(byte);
    }
    return btoa(binary);
}
function postJson(url, body, options) {
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    if (options.csrf) {
        headers[options.csrf.header] = options.csrf.token;
    }
    return fetch(url, { method: 'POST', headers, body: JSON.stringify(body), credentials: 'same-origin', signal: options.signal });
}
/**
 * XMLHttpRequest instead of fetch(): fetch has no upload progress events.
 */
function put(presign, file, options) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const abort = () => xhr.abort();
        options.signal?.addEventListener('abort', abort, { once: true });
        const settle = () => options.signal?.removeEventListener('abort', abort);
        xhr.open(presign.method, presign.url);
        for (const [name, value] of Object.entries(presign.headers)) {
            xhr.setRequestHeader(name, value);
        }
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                options.onProgress?.(event.loaded, event.total);
            }
        });
        xhr.addEventListener('load', () => {
            settle();
            resolve(xhr.status);
        });
        xhr.addEventListener('error', () => {
            settle();
            reject(new UploadError(['Network error during upload.']));
        });
        xhr.addEventListener('abort', () => {
            settle();
            reject(new DOMException('The upload was aborted.', 'AbortError'));
        });
        if (options.signal?.aborted) {
            xhr.abort();
            return;
        }
        xhr.send(file);
    });
}
async function serverError(response, fallback) {
    const body = await response.json().catch(() => null);
    if (typeof body === 'object' && body !== null) {
        if ('violations' in body && Array.isArray(body.violations) && body.violations.length > 0) {
            return new UploadError(body.violations.map((violation) => violation.message), true);
        }
        if ('message' in body && typeof body.message === 'string') {
            return new UploadError([body.message], true);
        }
    }
    return new UploadError([fallback]);
}
