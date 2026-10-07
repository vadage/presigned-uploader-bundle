# Contributing

Thank you for considering a contribution! Bug reports, documentation improvements and pull requests are welcome.
For larger changes or new features, please open an issue first to discuss the idea.

Please report security issues privately, see the [security policy](SECURITY.md).

## Development setup

```bash
git clone https://github.com/vadage/presigned-uploader-bundle.git
cd presigned-uploader-bundle
composer install
```

The integration tests need an S3-compatible storage and, optionally, MySQL or PostgreSQL. `compose.yaml`
provides SeaweedFS (port 8333), MySQL (port 3307) and PostgreSQL (port 5433), plus a `php` service with all
database drivers:

```bash
docker compose up -d --wait
```

## Running the tests

```bash
composer test                       # PHPUnit; Doctrine tests use SQLite (pdo_sqlite) and are skipped without it

# or in the php container, against each database:
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm -e DATABASE_URL='mysql://test:test@127.0.0.1:3307/test?serverVersion=9.7' php vendor/bin/phpunit
docker compose run --rm -e DATABASE_URL='postgresql://test:test@127.0.0.1:5433/test?serverVersion=18' php vendor/bin/phpunit
```

The tests expect SeaweedFS at `http://127.0.0.1:8333`; set `SEAWEEDFS_ENDPOINT` to use another address.

## Code quality

```bash
composer phpstan   # PHPStan level 10 with strict rules and the Symfony, Doctrine and PHPUnit extensions
composer cs        # Symfony coding standard (composer cs-fix applies it)
composer rector    # automated refactorings and upgrades (composer rector-fix applies them)
```

CI runs all of these, and PHPUnit against the supported PHP versions with highest and lowest dependencies,
and against SQLite, MySQL and PostgreSQL.

## JavaScript

The Stimulus controller and the client are written in TypeScript in `assets/src/`:

```bash
cd assets
npm ci
npm run typecheck
npm test
npm run build      # compiles src/*.ts to dist/ and types/
```

`assets/dist/` and `assets/types/` are committed, so applications using AssetMapper need no build step. Run
`npm run build` and commit the result with your changes; CI fails when the compiled files do not match the
sources.

## Pull requests

- Base your branch on `main` and keep each pull request focused on one change.
- Add or update tests for bug fixes and new features, and update the documentation in `docs/`.
- Add an entry to the `[Unreleased]` section of `CHANGELOG.md` for user-facing changes.
- Make sure the tests, PHPStan, the coding standard, Rector and the JavaScript checks pass.
- Write commit messages in the imperative mood ("Add ...", "Fix ..."), with a short summary line.
- Do not change the public API in a backwards incompatible way without discussing it first, see the
  [backward compatibility policy](README.md#backward-compatibility).

## AI-assisted contributions

Contributions written with the help of AI tools are welcome, under the same rules as any other:

- Disclose it with an `Assisted-by: AGENT_NAME:MODEL_VERSION [TOOL...]` trailer in the commit message, listing
  analysis tools only when they drove the change (e.g. `Assisted-by: claude-code:claude-opus-5-5 phpstan`).
- You are the author: understand and be able to explain every change you submit, and test it yourself.
- Do not submit generated issues, reviews or pull requests you have not read and checked.
