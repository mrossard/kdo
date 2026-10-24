# AGENTS.md

## Project

KDO — gift list / gift project management app. Backend-only Symfony app, no REST API.

## Stack

- **PHP 8.5**, Symfony 8, Doctrine ORM 3, PostgreSQL 16
- **Frontend**: Bulma CSS, HTMX. Minimal JS (`assets/app.js`). Use Symfony UX Icons for icons.
- **Server**: FrankenPHP (Caddy) via Docker
- **Linter**: Mago (not PHP-CS-Fixer or Rector). Run `vendor/bin/mago lint` and `vendor/bin/mago format`.

## Key commands

```bash
composer install                    # install deps (auto-scripts run cache:clear + importmap:install)
docker compose up -d database       # start Postgres only (dev with local PHP)
php bin/console doctrine:migrations:diff   # generate migration
php bin/console doctrine:migrations:migrate -n  # apply migrations
php bin/console doctrine:fixtures:load -n  # load fixtures (dev)
docker compose exec php php bin/phpunit    # run test suite (or php bin/phpunit tests/Service tests/Entity for unit tests)
vendor/bin/mago lint                # lint
vendor/bin/mago analyze             # static analysis
vendor/bin/mago format              # auto-format
```

## Database

- Postgres, configured via `DATABASE_URL` in `.env` / `.env.dev`
- Fixtures bundle is in require-dev: `doctrine:fixtures:load -n`
- UUID type is mapped globally in `config/packages/doctrine.yaml`

## Dev environment

- `compose.override.yaml` is auto-loaded by `docker compose up`. It starts Postgres + MailHog.
- The dev Docker target is `frankenphp_dev` (hot-reload via `--watch`).
- Mailer: MailHog on port 8025 (dev only).
- Web Profiler is enabled in dev.

## Frontend assets

- Symfony Asset Mapper (no Webpack Encore or AssetMapper CLI).
- `importmap.php` at root defines entrypoints. Add vendor packages with `composer importmap:require`.
- After adding/modifying assets for prod: `php bin/console asset-map:compile`.

## Code conventions

- PSR-12. Typed properties and return types. No dynamic properties.
- Entities use Doctrine attributes. PSR-4: `App\` → `src/`.
- Prefer HTMX for interactivity. Avoid custom JavaScript unless Bulma/HTMX cannot cover the need.
