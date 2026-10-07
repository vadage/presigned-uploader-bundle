import { Controller } from '@hotwired/stimulus';
/**
 * Uploads the selected file and puts the upload id into the hidden form field.
 * Dispatches (prefixed with the controller identifier): start, progress, success, error, abort.
 */
export default class extends Controller<HTMLElement> {
    static targets: string[];
    static values: {
        presignUrl: StringConstructor;
        csrfHeader: {
            type: StringConstructor;
            default: string;
        };
        csrfToken: StringConstructor;
        maxSize: NumberConstructor;
        checksum: BooleanConstructor;
        busyMessage: {
            type: StringConstructor;
            default: string;
        };
        tooLargeMessage: {
            type: StringConstructor;
            default: string;
        };
        failedMessage: {
            type: StringConstructor;
            default: string;
        };
    };
    readonly fileTarget: HTMLInputElement;
    readonly tokenTarget: HTMLInputElement;
    readonly progressTarget: HTMLProgressElement;
    readonly hasProgressTarget: boolean;
    readonly errorsTarget: HTMLElement;
    readonly hasErrorsTarget: boolean;
    readonly presignUrlValue: string;
    readonly csrfHeaderValue: string;
    readonly csrfTokenValue: string;
    readonly maxSizeValue: number;
    readonly checksumValue: boolean;
    readonly busyMessageValue: string;
    readonly tooLargeMessageValue: string;
    readonly failedMessageValue: string;
    private abortController;
    private form;
    private readonly blockSubmit;
    connect(): void;
    disconnect(): void;
    upload(): Promise<void>;
    private setProgress;
    private showErrors;
    private clearErrors;
}
