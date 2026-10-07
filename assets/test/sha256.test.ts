import { describe, expect, it } from 'vitest';
import { Sha256 } from '../src/sha256.js';

async function webCrypto(data: Uint8Array<ArrayBuffer>): Promise<string> {
    return hex(new Uint8Array(await crypto.subtle.digest('SHA-256', data)));
}

function hex(bytes: Uint8Array): string {
    return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function bytes(length: number): Uint8Array<ArrayBuffer> {
    // Deterministic, not all zero: catches byte order and carry mistakes.
    return Uint8Array.from({ length }, (_, i) => (i * 31 + 7) & 0xff);
}

describe('Sha256', () => {
    it('matches the FIPS 180-4 example', () => {
        expect(hex(new Sha256().update(new TextEncoder().encode('abc')).digest())).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
    });

    // Around the padding boundaries (55/56 bytes) and block boundaries (64 bytes).
    it.each([0, 1, 55, 56, 57, 63, 64, 65, 119, 120, 128, 1000, 100_003])('matches WebCrypto for %i bytes', async (length) => {
        const data = bytes(length);

        expect(hex(new Sha256().update(data).digest())).toBe(await webCrypto(data));
    });

    it('gives the same digest however the input is split', async () => {
        const data = bytes(10_000);
        const hash = new Sha256().update(new Uint8Array(0));
        let offset = 0;
        for (const size of [1, 63, 64, 65, 3, 4096]) {
            hash.update(data.subarray(offset, offset + size));
            offset += size;
        }
        hash.update(data.subarray(offset));

        expect(hex(hash.digest())).toBe(await webCrypto(data));
    });
});
