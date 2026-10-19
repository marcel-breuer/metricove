# Metricove API

The API is a Laravel application. Use PHP 8.3 or newer and Composer.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=0.0.0.0 --port=8000
```

The framework health check is available at `/up`, and the JSON API health check is available at `/api/health`. Database-backed features use PostgreSQL and Redis; set their connection values with the `DB_*` and `REDIS_*` environment variables.

On a fresh installation, `GET /api/setup/status` reports whether the one-time setup is available. The web app uses this to show the setup form. `POST /api/setup` accepts a display name, normalized email, password and confirmation; it creates the household's first active administrator once, starts a web session, and then returns `409 Conflict` for later attempts. The setup routes use Laravel's web session and CSRF middleware.

Run the API regression tests with `composer test` when PHP has SQLite support. The Docker test target installs the development dependencies and runs the same suite during its build without requiring host PHP extensions.
