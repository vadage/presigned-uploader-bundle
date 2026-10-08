import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { upload, UploadError } from '../src/client.js';
import { FakeXhr, json } from './setup.js';

const options = { presignUrl: '/uploads/avatar' };
const presigned = { uploadId: 'abc.def', method: 'PUT', url: 'https://storage/key', headers: { 'Content-Type': 'image/png', 'If-None-Match': '*' }, expiresAt: '', verifyUrl: '/uploads/abc.def/verify' };

describe('upload()', () => {
    beforeEach(() => {
        FakeXhr.status = 200;
        FakeXhr.instances = [];
        vi.stubGlobal('XMLHttpRequest', FakeXhr);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('presigns, uploads with exactly the returned headers and verifies', async () => {
        const fetch = vi.fn().mockReturnValueOnce(json(201, presigned)).mockReturnValueOnce(json(200, { state: 'verified' }));
        vi.stubGlobal('fetch', fetch);
        const file = new File(['hello'], 'a.png', { type: 'image/png' });

        await expect(upload(file, { ...options, csrf: { header: 'X-CSRF-Token', token: 'csrf' } })).resolves.toBe('abc.def');

        const [presignUrl, presignInit] = fetch.mock.calls[0] as [string, RequestInit];
        expect(presignUrl).toBe('/uploads/avatar');
        expect((presignInit.headers as Record<string, string>)['X-CSRF-Token']).toBe('csrf');
        expect(JSON.parse(presignInit.body as string)).toEqual({ filename: 'a.png', size: 5, mimeType: 'image/png' });
        expect(FakeXhr.instances[0]?.headers).toEqual(presigned.headers);
        expect(FakeXhr.instances[0]?.body).toBe(file);
        expect(fetch.mock.calls[1]?.[0]).toBe('/uploads/abc.def/verify');
    });

    it('skips verification when disabled', async () => {
        const fetch = vi.fn().mockReturnValueOnce(json(201, presigned));
        vi.stubGlobal('fetch', fetch);

        await expect(upload(new File(['hello'], 'a.png'), { ...options, verify: false })).resolves.toBe('abc.def');
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('rejects when the stored file fails verification', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockReturnValueOnce(json(201, presigned))
            .mockReturnValueOnce(json(422, { state: 'rejected', violations: [{ propertyPath: '', message: 'The mime type of the file is invalid.' }] })));

        const error = await upload(new File(['hello'], 'a.png'), options).catch((e: unknown) => e);

        expect((error as UploadError).messages).toEqual(['The mime type of the file is invalid.']);
    });

    it('keeps the upload when verification is not conclusive', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockReturnValueOnce(json(201, presigned))
            .mockReturnValueOnce(json(503, {})));

        await expect(upload(new File(['hello'], 'a.png'), options)).resolves.toBe('abc.def');
    });

    it('sends a base64 SHA-256 when checksums are enabled', async () => {
        const fetch = vi.fn().mockReturnValueOnce(json(201, presigned)).mockReturnValueOnce(json(200, {}));
        vi.stubGlobal('fetch', fetch);

        await upload(new File(['hello'], 'a.png'), { ...options, checksum: true });

        expect(JSON.parse((fetch.mock.calls[0] as [string, RequestInit])[1].body as string).sha256).toBe('LPJNul+wow4m6DsqxbninhsWHlwfp0JecwQzYpOLmCQ=');
    });

    it('rejects with the server-side violations', async () => {
        vi.stubGlobal('fetch', vi.fn().mockReturnValueOnce(json(422, { violations: [{ propertyPath: '', message: 'The file is too large.' }] })));

        const error = await upload(new File(['hello'], 'a.png'), options).catch((e: unknown) => e);

        expect(error).toBeInstanceOf(UploadError);
        expect((error as UploadError).messages).toEqual(['The file is too large.']);
        expect(FakeXhr.instances).toHaveLength(0);
    });

    it('treats 412 as already uploaded and lets the server verify', async () => {
        FakeXhr.status = 412;
        vi.stubGlobal('fetch', vi.fn().mockReturnValueOnce(json(201, presigned)).mockReturnValueOnce(json(200, {})));

        await expect(upload(new File(['hello'], 'a.png'), options)).resolves.toBe('abc.def');
    });

    it('aborts the storage upload when the signal aborts', async () => {
        vi.stubGlobal('fetch', vi.fn().mockReturnValueOnce(json(201, presigned)));
        vi.spyOn(FakeXhr.prototype, 'send').mockImplementation(() => {});
        const controller = new AbortController();

        const result = upload(new File(['hello'], 'a.png'), { ...options, signal: controller.signal });
        await vi.waitFor(() => expect(FakeXhr.instances).toHaveLength(1));
        controller.abort();

        await expect(result).rejects.toMatchObject({ name: 'AbortError' });
    });
    it('presigns and verifies through functions, e.g. GraphQL mutations', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const presign = vi.fn().mockResolvedValue({ uploadId: 'abc.def', method: 'PUT', url: 'https://storage/key', headers: presigned.headers });
        const verify = vi.fn().mockResolvedValue({ state: 'verified', violations: [] });

        await expect(upload(new File(['hello'], 'a.png', { type: 'image/png' }), { presign, verify, checksum: true })).resolves.toBe('abc.def');

        expect(presign).toHaveBeenCalledWith({ filename: 'a.png', size: 5, mimeType: 'image/png', sha256: 'LPJNul+wow4m6DsqxbninhsWHlwfp0JecwQzYpOLmCQ=' });
        expect(FakeXhr.instances[0]?.headers).toEqual(presigned.headers);
        expect(verify).toHaveBeenCalledWith('abc.def');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('skips verification without a verify function or URL', async () => {
        const presign = vi.fn().mockResolvedValue({ uploadId: 'abc.def', method: 'PUT', url: 'https://storage/key', headers: {} });
        vi.stubGlobal('fetch', vi.fn());

        await expect(upload(new File(['hello'], 'a.png'), { presign })).resolves.toBe('abc.def');
    });

    it('passes on errors of the presign function', async () => {
        const presign = vi.fn().mockRejectedValue(new UploadError(['The file is too large.'], true));

        await expect(upload(new File(['hello'], 'a.png'), { presign })).rejects.toMatchObject({ messages: ['The file is too large.'] });
        expect(FakeXhr.instances).toHaveLength(0);
    });

    it('rejects when the verify function reports a rejected file', async () => {
        const presign = vi.fn().mockResolvedValue({ uploadId: 'abc.def', method: 'PUT', url: 'https://storage/key', headers: {} });
        const verify = vi.fn().mockResolvedValue({ state: 'rejected', violations: [{ propertyPath: '', message: 'The mime type of the file is invalid.' }] });

        const error = await upload(new File(['hello'], 'a.png'), { presign, verify }).catch((e: unknown) => e);

        expect((error as UploadError).messages).toEqual(['The mime type of the file is invalid.']);
        expect((error as UploadError).fromServer).toBe(true);
    });

    it('keeps the upload when the verify function fails otherwise', async () => {
        const presign = vi.fn().mockResolvedValue({ uploadId: 'abc.def', method: 'PUT', url: 'https://storage/key', headers: {} });
        const verify = vi.fn().mockRejectedValue(new Error('Network error'));

        await expect(upload(new File(['hello'], 'a.png'), { presign, verify })).resolves.toBe('abc.def');
    });

    it('requires a presign URL or function', async () => {
        await expect(upload(new File(['hello'], 'a.png'), {})).rejects.toBeInstanceOf(TypeError);
    });
});
