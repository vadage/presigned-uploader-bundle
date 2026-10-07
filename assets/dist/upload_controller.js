import { Controller } from '@hotwired/stimulus';
import { upload, UploadError } from './client.js';
/**
 * Uploads the selected file and puts the upload id into the hidden form field.
 * Dispatches (prefixed with the controller identifier): start, progress, success, error, abort.
 */
export default class extends Controller {
    static targets = ['file', 'token', 'progress', 'errors'];
    static values = {
        presignUrl: String,
        csrfHeader: { type: String, default: 'X-CSRF-Token' },
        csrfToken: String,
        maxSize: Number,
        checksum: Boolean,
        busyMessage: { type: String, default: 'Please wait until the upload has finished.' },
        tooLargeMessage: { type: String, default: 'The file is too large. Allowed maximum size is {{ limit }}.' },
        failedMessage: { type: String, default: 'The upload failed, please try again.' },
    };
    abortController = null;
    form = null;
    blockSubmit = (event) => {
        if (this.abortController !== null) {
            event.preventDefault();
            this.showErrors([this.busyMessageValue]);
        }
    };
    connect() {
        this.form = this.element.closest('form');
        this.form?.addEventListener('submit', this.blockSubmit);
    }
    disconnect() {
        this.abortController?.abort();
        this.form?.removeEventListener('submit', this.blockSubmit);
    }
    async upload() {
        this.abortController?.abort();
        this.clearErrors();
        this.tokenTarget.value = '';
        const file = this.fileTarget.files?.[0];
        if (!file) {
            return;
        }
        if (this.maxSizeValue > 0 && file.size > this.maxSizeValue) {
            this.showErrors([this.tooLargeMessageValue.replace('{{ limit }}', formatSize(this.maxSizeValue))]);
            this.fileTarget.value = '';
            return;
        }
        const abortController = new AbortController();
        this.abortController = abortController;
        this.dispatch('start', { detail: { file } });
        try {
            const uploadId = await upload(file, {
                presignUrl: this.presignUrlValue,
                csrf: this.csrfTokenValue ? { header: this.csrfHeaderValue, token: this.csrfTokenValue } : undefined,
                checksum: this.checksumValue,
                signal: abortController.signal,
                onProgress: (loaded, total) => {
                    const percent = Math.round((loaded / total) * 100);
                    this.setProgress(percent);
                    this.dispatch('progress', { detail: { file, loaded, total, percent } });
                },
            });
            this.tokenTarget.value = uploadId;
            this.setProgress(100);
            this.dispatch('success', { detail: { file, uploadId } });
        }
        catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                this.dispatch('abort', { detail: { file } });
                return;
            }
            // The server's messages are translated and say what is wrong; anything else gets the generic message.
            const messages = error instanceof UploadError && error.fromServer ? error.messages : [this.failedMessageValue];
            this.fileTarget.value = '';
            this.showErrors(messages);
            this.dispatch('error', { detail: { file, messages, error } });
        }
        finally {
            if (this.abortController === abortController) {
                this.abortController = null;
            }
        }
    }
    setProgress(percent) {
        if (this.hasProgressTarget) {
            this.progressTarget.hidden = false;
            this.progressTarget.value = percent;
        }
    }
    showErrors(messages) {
        if (this.hasErrorsTarget) {
            this.errorsTarget.replaceChildren(...messages.map((message) => {
                const element = document.createElement('div');
                element.textContent = message;
                return element;
            }));
        }
    }
    clearErrors() {
        if (this.hasErrorsTarget) {
            this.errorsTarget.replaceChildren();
        }
        if (this.hasProgressTarget) {
            this.progressTarget.hidden = true;
        }
    }
}
function formatSize(bytes) {
    for (const [suffix, factor] of [['GB', 1e9], ['MB', 1e6], ['kB', 1e3]]) {
        if (bytes >= factor) {
            return `${Math.round((bytes / factor) * 100) / 100} ${suffix}`;
        }
    }
    return `${bytes} bytes`;
}
