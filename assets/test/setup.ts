// jsdom lacks Blob.arrayBuffer() (all browsers have it). Copy into this realm's buffer for WebCrypto.
if (!File.prototype.arrayBuffer) {
    File.prototype.arrayBuffer = function (this: File): Promise<ArrayBuffer> {
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = () => resolve(Uint8Array.from(new Uint8Array(reader.result as ArrayBuffer)).buffer);
            reader.readAsArrayBuffer(this);
        });
    };
}

type Listener = () => void;

/**
 * Stands in for the storage: records the PUT and answers with FakeXhr.status.
 */
export class FakeXhr {
    static status = 200;
    static instances: FakeXhr[] = [];

    method = '';
    url = '';
    body: unknown = null;
    status = 0;
    headers: Record<string, string> = {};
    readonly upload = { addEventListener: (): void => {} };
    private readonly listeners: Record<string, Listener> = {};

    constructor() {
        FakeXhr.instances.push(this);
    }

    open(method: string, url: string): void {
        this.method = method;
        this.url = url;
    }

    setRequestHeader(name: string, value: string): void {
        this.headers[name] = value;
    }

    addEventListener(name: string, listener: Listener): void {
        this.listeners[name] = listener;
    }

    send(body: unknown): void {
        this.body = body;
        this.status = FakeXhr.status;
        queueMicrotask(() => this.listeners.load?.());
    }

    abort(): void {
        this.listeners.abort?.();
    }
}

export function json(status: number, body: unknown): Promise<Response> {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}
