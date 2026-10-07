# Installation

## Requirements

- PHP 8.2 or higher, with `ext-fileinfo` (content sniffing uses libmagic)
- Symfony 7.4 LTS or 8.x
- [async-aws/s3](https://async-aws.com/clients/s3.html) for presigning and verifying uploads
- Doctrine ORM 3 / DBAL 4 with DoctrineBundle, unless you provide your own
  [pending upload repository](events-and-extension-points.md#pending-upload-repository)
- Sessions enabled (`framework.session`) when anonymous users upload files: their uploads belong to the session

Everything else is optional and enabled when its package is installed:

| Feature                                                                   | Packages                                                                           |
|---------------------------------------------------------------------------|------------------------------------------------------------------------------------|
| Configuring S3 clients in YAML                                            | `async-aws/async-aws-bundle`                                                       |
| Pending upload storage, `StoredObject` columns, claim and delete on flush | `doctrine/orm`, `doctrine/doctrine-bundle`                                         |
| `PresignedUploadType` and its widget                                      | `symfony/form`, `symfony/twig-bundle`, `symfony/stimulus-bundle`                   |
| CSRF tokens on the upload endpoints                                       | `symfony/security-csrf`                                                            |
| Upload ids and URLs in APIs (API Platform, `#[MapRequestPayload]`)        | `symfony/serializer` (with `symfony/property-info`)                                |
| OpenAPI documentation and GraphQL types for API Platform                  | API Platform 4.4 or 5 (`api-platform/core` or the split packages); GraphQL needs 5 |
| Translated messages (English, German, French)                             | `symfony/translation`                                                              |
| `ObjectCreated` message for storage events                                | `symfony/messenger`                                                                |
| Storage webhooks                                                          | `symfony/webhook`, `symfony/remote-event`, `symfony/messenger`                     |
| Cleanup command                                                           | `symfony/console`                                                                  |
| Flysystem storages                                                        | `league/flysystem-bundle` (or any `League\Flysystem\FilesystemOperator` service)   |

Make sure Composer is installed globally, as explained in the
[installation chapter](https://getcomposer.org/doc/00-intro.md) of the Composer documentation.

## Applications that use Symfony Flex

The Flex recipes of this bundle and of async-aws/async-aws-bundle live in the
[contrib repository](https://github.com/symfony/recipes-contrib). Allow contrib recipes first; otherwise Flex
skips them when Composer runs non-interactively. Then open a command console, enter your project directory and
execute:

```bash
composer config extra.symfony.allow-contrib true
composer require vadage/presigned-uploader-bundle async-aws/async-aws-bundle doctrine/orm doctrine/doctrine-bundle
```

The recipe enables the bundle, creates `config/packages/vadage_presigned_uploader.yaml` and imports the routes
in `config/routes/vadage_presigned_uploader.yaml`. Continue with [Configure the S3 clients](#configure-the-s3-clients).

## Applications that don't use Symfony Flex

### Step 1: Download the bundle

Open a command console, enter your project directory and execute the following command to download the latest
stable version of this bundle:

```bash
composer require vadage/presigned-uploader-bundle async-aws/async-aws-bundle doctrine/orm doctrine/doctrine-bundle
```

### Step 2: Enable the bundle

Enable the bundle by adding it to the list of registered bundles in the `config/bundles.php` file of your
project:

```php
// config/bundles.php
return [
    // ...
    AsyncAws\Symfony\Bundle\AsyncAwsBundle::class => ['all' => true],
    Vadage\PresignedUploaderBundle\VadagePresignedUploaderBundle::class => ['all' => true],
];
```

### Step 3: Import the routes

```yaml
# config/routes/vadage_presigned_uploader.yaml
vadage_presigned_uploader:
    resource: '@VadagePresignedUploaderBundle/config/routes.php'
    prefix: /uploads
```

The routes are `POST /uploads/{mapping}` (presign) and `POST /uploads/{uploadId}/verify` (optional verify call).
You can choose another prefix; the form widget generates the URLs from the routes.

## Configure the S3 clients

Configure one S3 client per account and endpoint, e.g. with the async-aws bundle. For Cloudflare R2:

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
```

The bundle references clients by service id; the async-aws bundle registers the client above as
`async_aws.client.r2`. Any `AsyncAws\S3\S3Client` service works.

## Configure the storages

The bundle configuration only describes infrastructure. Upload rules live on your classes
(see [Mapping and validation](mapping-and-validation.md)).

```yaml
# config/packages/vadage_presigned_uploader.yaml
vadage_presigned_uploader:
    storages:
        documents:                  # private bucket, read via presigned GET URLs
            client: async_aws.client.r2
            bucket: documents
        media:                      # public bucket served from a custom domain
            client: async_aws.client.r2
            bucket: media
            public_url: 'https://media.example.com'
    defaults:
        max_size: 50M               # optional, see below
```

Every mapping needs a maximum size, from `#[PresignedFile(maxSize: ...)]` or `defaults.max_size`: the container
does not compile without one. See [Storages](storages.md) for staging, Flysystem storages and storage-specific
notes, and the [configuration reference](configuration-reference.md) for all options.

## Create the database tables

With Doctrine, the bundle registers its two entities and the `presigned_stored_object` column type
automatically. Create the `vadage_presigned_upload` and `vadage_presigned_upload_tombstone` tables with a
migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

The bundle maps its entities in your default entity manager. Keep them in the entity manager of the entities
you map, so claims and deletions are tied to your transactions (see
[Upload lifecycle](upload-lifecycle.md#transactions)); set `pending_upload.entity_manager` if that is not the
default one.

## Set up the frontend

The form widget uses a Stimulus controller shipped in `assets/` of the package (no build step required).
Install `symfony/stimulus-bundle` if your application does not use Stimulus yet.

### AssetMapper

The bundle maps its compiled assets under the `@vadage/presigned-uploader-bundle` namespace. When the bundle
is installed, Flex registers the controller in `assets/controllers.json`:

```json
{
    "controllers": {
        "@vadage/presigned-uploader-bundle": {
            "upload": {
                "enabled": true,
                "fetch": "lazy"
            }
        }
    },
    "entrypoints": []
}
```

Without Flex, add this entry yourself. Its identifier is `vadage--presigned-uploader-bundle--upload`.

### Webpack Encore

Flex adds the package to your `package.json` and registers the controller in `assets/controllers.json`:

```json
{
    "devDependencies": {
        "@vadage/presigned-uploader-bundle": "file:vendor/vadage/presigned-uploader-bundle/assets"
    }
}
```

Install it and rebuild your assets:

```bash
npm install --force
npm run build
```

To use the client without Stimulus, see [JavaScript client](javascript-client.md).

## Allow uploads from the browser (CORS)

The browser sends the `PUT` request to the storage, so the bucket needs a CORS rule for your origin, e.g. for
R2 and S3 (JSON):

```json
[{
    "AllowedOrigins": ["https://app.example.com"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["content-type", "if-none-match", "x-amz-checksum-sha256"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
}]
```

Add `GET` to `AllowedMethods` if your frontend fetches stored files with JavaScript.

## Schedule the cleanup

Uploads that are never claimed and objects that are no longer referenced are deleted by a console command.
Run it every few minutes, see [Cleanup](upload-lifecycle.md#cleanup).
