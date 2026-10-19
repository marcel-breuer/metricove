# Metricove API

The API is a Laravel application. Use PHP 8.3 or newer and Composer.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=0.0.0.0 --port=8000
```

The framework health check is available at `/up`, and the JSON API health check is available at `/api/health`. Database-backed features use PostgreSQL and Redis; set their connection values with the `DB_*` and `REDIS_*` environment variables.

Run the API smoke tests with `composer test`.
