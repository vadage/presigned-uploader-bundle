# Forms and the Stimulus controller

## Adding the field

For properties mapped with `#[UploadableField]`, the type guesser picks `PresignedUploadType` and the mapping:

```php
$builder->add('avatar');
```

Or add it explicitly, e.g. in a form without `data_class`:

```php
use Vadage\PresignedUploaderBundle\Form\PresignedUploadType;

$builder->add('avatar', PresignedUploadType::class, ['mapping' => 'user_avatar']);
```

The widget renders a file input, a progress bar, an error container and a hidden field. When the user selects a
file, the Stimulus controller uploads it directly to the storage and puts the upload id into the hidden field;
the form submits only that id.

On submit, the field's data becomes:

- a new `StoredObject` when a file was uploaded (it replaces the current one),
- `null` when the delete checkbox was checked,
- the current value otherwise.

The upload is resolved while the form is submitted: it must exist, belong to the current user or session, not
be expired, and pass [verification](upload-lifecycle.md#verification). If verification at submit time rejects
the file, the field shows why; in all other cases (including a file the verify call or a storage event rejected
earlier, which the Stimulus controller reports right away) it shows the `invalid_message`. With Doctrine, the upload is claimed when you
flush the entity. Without Doctrine, [claim it yourself](upload-lifecycle.md#without-doctrine-dtos).

## Options

| Option            | Default                                                               | Description                                                                                 |
|-------------------|-----------------------------------------------------------------------|---------------------------------------------------------------------------------------------|
| `mapping`         | guessed                                                               | Name of the mapping (`#[UploadableField(name: ...)]`); required when not guessed            |
| `allow_delete`    | `true`                                                                | Render a checkbox that clears the current object                                            |
| `delete_label`    | `'Delete'`                                                            | Label of the checkbox, translated in the `VadagePresignedUploaderBundle` domain             |
| `download_uri`    | `true`                                                                | Link the current file's name to its [URL](mapping-and-validation.md#reading-stored-objects) |
| `invalid_message` | `'The uploaded file is no longer available, please upload it again.'` | Shown when the submitted upload cannot be used                                              |

The delete checkbox is only rendered when the field has a current object.

## Form theme

The bundle registers its form theme (`@VadagePresignedUploader/form/theme.html.twig`) automatically when
TwigBundle is installed. It defines the `vadage_presigned_upload_widget` block. To change the markup, override
that block in one of your own form themes:

```twig
{# templates/form/uploads.html.twig #}
{% block vadage_presigned_upload_widget %}
    {# your markup, keep the data-* attributes of the original block #}
{% endblock %}
```

```yaml
# config/packages/twig.yaml
twig:
    form_themes: ['form/uploads.html.twig']
```

Start from a copy of the original block. It uses these variables:

| Variable                    | Content                                                                          |
|-----------------------------|----------------------------------------------------------------------------------|
| `stimulus_controller`       | The controller identifier, `vadage--presigned-uploader-bundle--upload`           |
| `presign_url`               | URL of the presign endpoint; the verify URL comes with its response              |
| `csrf_header`, `csrf_token` | CSRF header name and token; the token is `null` without CSRF protection          |
| `checksum`                  | Whether the client must send a SHA-256 checksum                                  |
| `max_size`                  | Smallest `maxSize` of the `#[PresignedFile]` constraints, or `null`              |
| `accept`                    | Value for the file input's `accept` attribute, from `mimeTypes` and `extensions` |
| `stored_object`             | The current `StoredObject`, or `null`                                            |
| `download_url`              | URL of the current object, or `null`                                             |
| `translation_domain_ui`     | `VadagePresignedUploaderBundle`, the domain of the widget's messages             |

`max_size` and `accept` only improve the user experience; the server validates every upload.

## Stimulus controller

The controller is registered as `vadage--presigned-uploader-bundle--upload` (see
[Installation](installation.md#set-up-the-frontend)). The form theme sets everything up, so you only need the
following when you write your own markup.

### Targets

| Target     | Element                                                                                                                     |
|------------|-----------------------------------------------------------------------------------------------------------------------------|
| `file`     | The `<input type="file">`; trigger the upload with `data-action="change->vadage--presigned-uploader-bundle--upload#upload"` |
| `token`    | The hidden input that receives the upload id                                                                                |
| `progress` | Optional `<progress max="100">`, shown during the upload                                                                    |
| `errors`   | Optional element that receives error messages                                                                               |

### Values

| Value             | Type    | Default                                                       | Description                                                  |
|-------------------|---------|---------------------------------------------------------------|--------------------------------------------------------------|
| `presignUrl`      | String  |                                                               | URL of the presign endpoint for the mapping                  |
| `csrfHeader`      | String  | `X-CSRF-Token`                                                | Header that carries the CSRF token                           |
| `csrfToken`       | String  |                                                               | CSRF token; no header is sent when empty                     |
| `maxSize`         | Number  |                                                               | Files larger than this (bytes) are refused before uploading  |
| `checksum`        | Boolean | `false`                                                       | Compute and send a SHA-256 checksum                          |
| `busyMessage`     | String  | `Please wait until the upload has finished.`                  | Shown when the form is submitted during an upload            |
| `tooLargeMessage` | String  | `The file is too large. Allowed maximum size is {{ limit }}.` | Shown for files above `maxSize`                              |
| `failedMessage`   | String  | `The upload failed, please try again.`                        | Shown when an upload fails without a message from the server |

The form theme fills the three message values with translations from the `VadagePresignedUploaderBundle`
domain. Messages returned by the server (validation errors, a denied upload) are shown as they are.

While an upload is running, the controller prevents the surrounding form from being submitted. Selecting another
file aborts the running upload.

### Events

The controller dispatches these events on its element. They bubble, and their names are prefixed with the
controller identifier, e.g. `vadage--presigned-uploader-bundle--upload:success`:

| Event      | `detail`                           |
|------------|------------------------------------|
| `start`    | `{ file }`                         |
| `progress` | `{ file, loaded, total, percent }` |
| `success`  | `{ file, uploadId }`               |
| `error`    | `{ file, messages, error }`        |
| `abort`    | `{ file }`                         |

React to them from another controller:

```html
<div data-action="vadage--presigned-uploader-bundle--upload:success->preview#show">
    {{ form_widget(form.avatar) }}
</div>
```

or with a plain listener:

```js
document.addEventListener('vadage--presigned-uploader-bundle--upload:success', (event) => {
    console.log('Uploaded', event.detail.file.name, event.detail.uploadId);
});
```
