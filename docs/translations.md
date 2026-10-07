# Translations

Messages are translated when symfony/translation is installed. English, German and French are included, in two
translation domains:

| Domain                          | Messages                                                                                                                                          |
|---------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| `validators`                    | Validation and verification messages, e.g. `The file is too large.`, the default messages of `#[PresignedFile]`, and the form's `invalid_message` |
| `VadagePresignedUploaderBundle` | The widget: the `Delete` label and the Stimulus controller's busy, too-large and failed messages                                                  |

Validation messages returned by the presign and verify endpoints are translated into the current locale, so
the Stimulus controller and the [JavaScript client](javascript-client.md) show them in the user's language.

## Overriding messages

Translation files in your application take precedence over the bundle's. Use the English message as the key,
e.g. in `translations/VadagePresignedUploaderBundle.en.yaml`:

```yaml
'Delete': 'Remove file'
'The upload failed, please try again.': 'Something went wrong. Please try again.'
```

or in `translations/validators.en.yaml`:

```yaml
'An empty file is not allowed.': 'Please choose a file that is not empty.'
```

The bundle's files in `translations/` (XLIFF) list every message. To add a language, create files for both
domains in your application with the same keys, and contributions of new languages are welcome.

Custom messages passed to `#[PresignedFile]` (e.g. `mimeTypesMessage`) are translated in the `validators`
domain like any other constraint message. The `delete_label` form option is translated in the
`VadagePresignedUploaderBundle` domain. Reasons passed to `PreSignEvent::deny()` are returned as they are.
