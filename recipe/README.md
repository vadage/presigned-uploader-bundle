# Symfony Flex recipe

This directory holds the Flex recipe for `vadage/presigned-uploader-bundle`. It is not part of the Composer
package (it is excluded from the dist archive): the files are to be submitted to
[symfony/recipes-contrib](https://github.com/symfony/recipes-contrib), using the same directory layout
(`vadage/presigned-uploader-bundle/<version>/`).

| File                                                 | Purpose                                                                                 |
|------------------------------------------------------|-----------------------------------------------------------------------------------------|
| `0.1/manifest.json`                                  | Enables the bundle in all environments and copies `config/` into the application        |
| `0.1/config/packages/vadage_presigned_uploader.yaml` | A minimal configuration that compiles right after installation, with commented examples |
| `0.1/config/routes/vadage_presigned_uploader.yaml`   | Imports the upload endpoints under `/uploads`                                           |
| `0.1/post-install.txt`                               | Next steps shown by Flex after installation                                             |

The default configuration stores pending uploads with Doctrine, so the container only compiles once
`doctrine/orm` and `doctrine/doctrine-bundle` are installed (or `pending_upload.repository` points to another
implementation). The post-install message says so.

When a release changes what the recipe has to install, add a new version directory (e.g. `0.2/`) instead of
changing an existing one, and submit it to recipes-contrib.
