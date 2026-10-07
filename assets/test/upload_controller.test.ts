import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import UploadController from '../src/upload_controller.js';
import { FakeXhr, json } from './setup.js';

const id = 'upload';

async function render(maxSize = 0): Promise<HTMLElement> {
    document.body.innerHTML = `
        <form>
            <div data-controller="${id}"
                 data-${id}-presign-url-value="/uploads/avatar"
                 data-${id}-csrf-token-value="csrf"
                 data-${id}-max-size-value="${maxSize}">
                <input type="file" data-${id}-target="file" data-action="change->${id}#upload">
                <progress data-${id}-target="progress" hidden></progress>
                <div data-${id}-target="errors"></div>
                <input type="hidden" data-${id}-target="token">
            </div>
        </form>`;
    // Stimulus connects controllers from a MutationObserver.
    await new Promise((resolve) => setTimeout(resolve, 0));

    return document.querySelector<HTMLElement>(`[data-controller=${id}]`)!;
}

function selectFile(content: string): File {
    const input = document.querySelector<HTMLInputElement>('input[type=file]')!;
    const file = new File([content], 'a.png', { type: 'image/png' });
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    input.dispatchEvent(new Event('change'));

    return file;
}

function next(element: HTMLElement, event: string): Promise<Event> {
    return new Promise((resolve) => element.addEventListener(`${id}:${event}`, resolve, { once: true }));
}

describe('upload controller', () => {
    let application: Application;

    beforeEach(() => {
        FakeXhr.status = 200;
        FakeXhr.instances = [];
        vi.stubGlobal('XMLHttpRequest', FakeXhr);
        application = Application.start();
        application.register(id, UploadController);
    });

    afterEach(() => {
        application.stop();
        vi.unstubAllGlobals();
    });

    it('puts the upload id into the hidden field', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockReturnValueOnce(json(201, { uploadId: 'abc.def', method: 'PUT', url: 'u', headers: {}, verifyUrl: '/uploads/abc.def/verify' }))
            .mockReturnValueOnce(json(200, {})));
        const element = await render();

        const success = next(element, 'success');
        selectFile('hello');
        await success;

        expect(document.querySelector<HTMLInputElement>('input[type=hidden]')!.value).toBe('abc.def');
    });

    it('shows errors and clears the selection', async () => {
        vi.stubGlobal('fetch', vi.fn().mockReturnValueOnce(json(422, { violations: [{ propertyPath: '', message: 'The file is too large.' }] })));
        const element = await render();

        const error = next(element, 'error');
        selectFile('hello');
        await error;

        expect(document.querySelector(`[data-${id}-target=errors]`)!.textContent).toBe('The file is too large.');
        expect(document.querySelector<HTMLInputElement>('input[type=hidden]')!.value).toBe('');
    });

    it('shows the configured message for failures the server did not explain', async () => {
        vi.stubGlobal('fetch', vi.fn().mockReturnValueOnce(json(500, {})));
        const element = await render();
        element.setAttribute(`data-${id}-failed-message-value`, 'Hochladen fehlgeschlagen.');

        const error = next(element, 'error');
        selectFile('hello');
        await error;

        expect(document.querySelector(`[data-${id}-target=errors]`)!.textContent).toBe('Hochladen fehlgeschlagen.');
    });

    it('rejects too large files before contacting the server', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        await render(3);

        selectFile('hello');

        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector(`[data-${id}-target=errors]`)!.textContent).toContain('too large');
    });

    it('blocks form submission while uploading', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
        await render();

        selectFile('hello');
        const event = new Event('submit', { cancelable: true });
        document.querySelector('form')!.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });
});
