# VadagePresignedUploaderBundle

[![CI](https://img.shields.io/github/actions/workflow/status/vadage/presigned-uploader-bundle/ci.yaml?branch=main&style=flat-square&label=CI)](https://github.com/vadage/presigned-uploader-bundle/actions/workflows/ci.yaml)
[![Packagist Version](https://img.shields.io/packagist/v/vadage/presigned-uploader-bundle.svg?style=flat-square)](https://packagist.org/packages/vadage/presigned-uploader-bundle)
[![PHP Version](https://img.shields.io/packagist/dependency-v/vadage/presigned-uploader-bundle/php.svg?style=flat-square)](https://packagist.org/packages/vadage/presigned-uploader-bundle)
[![License](https://img.shields.io/github/license/vadage/presigned-uploader-bundle.svg?style=flat-square)](LICENSE)

Direct-to-storage uploads for Symfony with presigned URLs. The browser uploads straight to an
S3-compatible storage (AWS S3, Cloudflare R2, SeaweedFS, ...) and the file never passes through PHP: your
application validates and signs the upload, verifies the stored file, and attaches it to your entity or DTO.

Constraints are declared next to the property and checked before a URL is handed out. Size, content type and an
optional SHA-256 checksum are part of the signature, and a presigned URL can only write once. Before a file is
attached, it is checked again, including the type detected from its content. With Doctrine, claims and
deletions follow your transaction, so a rollback leaves no orphaned files and no referenced file gets deleted.

The bundle comes with a form type and Stimulus controller, a framework-agnostic JavaScript client, and
serializer support for APIs (API Platform, `#[MapRequestPayload]`). It handles multiple storages, staging with
promotion on claim, Flysystem targets, storage event webhooks (R2, S3) and the cleanup of unclaimed uploads.

## Requirements

- PHP 8.2 or higher, with `ext-fileinfo`
- Symfony 7.4 LTS or 8.x
- [async-aws/s3](https://async-aws.com/clients/s3.html) (installed with the bundle)
- Doctrine ORM 3 / DBAL 4 with DoctrineBundle (optional, needed for the default setup)

## Installation

```bash
composer require vadage/presigned-uploader-bundle async-aws/async-aws-bundle doctrine/orm doctrine/doctrine-bundle
```

With Symfony Flex, the recipe enables the bundle, imports the routes under `/uploads` and creates
`config/packages/vadage_presigned_uploader.yaml`. See [Installation](docs/installation.md) for setups without
Flex (or its recipe), the database tables, the frontend and the bucket's CORS rule.

Configure an S3 client and a storage:

```yaml
# config/packages/async_aws.yaml
async_aws:
    clients:
        r2:
            type: s3
            config:
                endpoint: 'https://%env(R2_ACCOUNT_ID)%.r2.cloudflarestorage.com'
                region: auto
                pathStyleEndpoint: true
                accessKeyId: '%env(R2_ACCESS_KEY_ID)%'
                accessKeySecret: '%env(R2_SECRET_ACCESS_KEY)%'

# config/packages/vadage_presigned_uploader.yaml
vadage_presigned_uploader:
    storages:
        media:
            client: async_aws.client.r2
            bucket: media
```

Map a property and declare its constraints:

```php
use Doctrine\ORM\Mapping as ORM;
use Vadage\PresignedUploaderBundle\Attribute\Uploadable;
use Vadage\PresignedUploaderBundle\Attribute\UploadableField;
use Vadage\PresignedUploaderBundle\Doctrine\StoredObjectType;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;

#[Uploadable]
#[ORM\Entity]
class User
{
    #[UploadableField(name: 'user_avatar', storage: 'media', prefix: 'avatars/')]
    #[PresignedFile(maxSize: '5M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp'])]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    private ?StoredObject $avatar = null;

    // getter and setter
}
```

Add it to a form. The type guesser picks `PresignedUploadType`; the Stimulus controller uploads the file and
the form submits only the upload id:

```php
$builder->add('avatar');
```

The upload is verified when the form is submitted, and claimed in the transaction that flushes the entity.
Create the database tables, set up the frontend and allow `PUT` requests in the bucket's CORS rule as
described in [Installation](docs/installation.md), and schedule the
[cleanup command](docs/upload-lifecycle.md#cleanup). The upload endpoints are public until you restrict them,
see [Security](docs/security.md).

## Documentation

- [Installation](docs/installation.md)
- [Mapping and validation](docs/mapping-and-validation.md)
- [Forms and the Stimulus controller](docs/forms.md)
- [JavaScript client and HTTP endpoints](docs/javascript-client.md)
- [APIs and API Platform](docs/api.md)
- [Storages, staging and Flysystem](docs/storages.md)
- [Upload lifecycle, transactions and cleanup](docs/upload-lifecycle.md)
- [Storage events and webhooks](docs/storage-events.md)
- [Security](docs/security.md)
- [Events and extension points](docs/events-and-extension-points.md)
- [Translations](docs/translations.md)
- [Configuration reference](docs/configuration-reference.md)

## Security issues

If you think you have found a security issue, please do not open a public issue. Follow the
[security policy](SECURITY.md) instead.

## Backward compatibility

From 1.0 on, the bundle follows [Semantic Versioning](https://semver.org/); code marked `@internal` and the markup
of the form theme are not covered. Until then, minor versions may contain breaking changes, listed in the
[changelog](CHANGELOG.md).

## AI assistance

The initial version of this bundle was built with heavy assistance from AI coding tools (Claude), directed
and reviewed by its author, who set the design, the public API and the scope. Later AI-assisted changes are
marked with an `Assisted-by:` trailer in their commit message, see [Contributing](CONTRIBUTING.md#ai-assisted-contributions).

All code, generated or not, is held to the same checks: PHPStan at level 10 with strict rules, the Symfony
coding standard, Rector, and PHPUnit against the supported PHP versions with highest and lowest dependencies
and against SQLite, MySQL and PostgreSQL.

The maintainers are responsible for the bundle: bugs and security issues are ours to fix, see the
[security policy](SECURITY.md).

## License

Released under the [MIT License](LICENSE).
