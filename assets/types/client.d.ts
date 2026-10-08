/**
 * Framework-agnostic upload client: presign, PUT directly to the storage, verify.
 * Usable without Stimulus, e.g. from React/Vue components or plain scripts.
 */
export interface UploadOptions {
    /** Either this or `presign` is required. */
    presignUrl?: string;
    /** Presigns through another transport, e.g. GraphQL. Throw an UploadError to report violations or a denial. */
    presign?: (descriptor: UploadDescriptor) => Promise<PresignedRequest>;
    /**
     * Verify the upload right after it finished (default), with the URL from the presign response or with a function.
     * Claiming the upload verifies it anyway, verifying early only reports a rejected file before the form is submitted.
     */
    verify?: boolean | ((uploadId: string) => Promise<VerifyResult>);
    csrf?: {
        header: string;
        token: string;
    };
    /** Send a SHA-256 checksum, required by mappings with checksum: true. */
    checksum?: boolean;
    signal?: AbortSignal;
    onProgress?: (loaded: number, total: number) => void;
}
export interface UploadDescriptor {
    filename: string;
    size: number;
    mimeType: string;
    sha256?: string;
}
export interface PresignedRequest {
    uploadId: string;
    method: string;
    url: string;
    headers: Record<string, string>;
    /** Without it, `verify: true` does not verify early. */
    verifyUrl?: string;
}
export interface VerifyResult {
    state: 'pending' | 'verified' | 'claiming' | 'rejected' | 'expired';
    violations: Violation[];
}
export interface Violation {
    propertyPath: string;
    message: string;
}
/** The server rejected the upload (validation, authorization, verification) or it failed on the way. */
export declare class UploadError extends Error {
    readonly messages: string[];
    readonly fromServer: boolean;
    /**
     * @param messages      why the upload failed, in English unless the server sent (translated) messages
     * @param fromServer    whether the messages come from the server's response
     */
    constructor(messages: string[], fromServer?: boolean);
}
/**
 * Resolves with the upload id to submit with the form. Rejects with an UploadError,
 * or a DOMException named "AbortError" when the signal aborts.
 */
export declare function upload(file: File, options: UploadOptions): Promise<string>;
/**
 * Base64 SHA-256 of a file, without loading large files into memory at once.
 */
export declare function sha256(file: Blob): Promise<string>;
