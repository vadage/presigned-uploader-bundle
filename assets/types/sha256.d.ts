/**
 * Incremental SHA-256 (FIPS 180-4). WebCrypto only hashes a whole buffer at once, which would load large
 * files into memory completely; this hashes them chunk by chunk.
 */
export declare class Sha256 {
    private readonly state;
    private readonly buffer;
    private readonly w;
    private buffered;
    private length;
    update(data: Uint8Array): this;
    digest(): Uint8Array;
    private compress;
}
