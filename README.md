# Makerly

Production and point of sale for workshops that make what they sell. One Laravel app, a PostgreSQL schema per workspace, and a React interface.

## Run locally

PostgreSQL must be running. The app database is `makerly` (user `makerly` / password `makerly`). Tests use a separate database named `makerly_test`.

PHP, Composer, and Node for this machine live under `%USERPROFILE%\dev-tools`. Add these to `PATH` before the commands below:

- `%USERPROFILE%\dev-tools\php`
- `%USERPROFILE%\dev-tools\node-v24.20.0-win-x64`
- `C:\Program Files\PostgreSQL\18\bin`

```bash
copy .env.example .env   # already configured in this checkout
php artisan migrate --force
php artisan db:seed --force
npm install
npm run build
php artisan serve
```

Open the demo workspace at http://acme.localhost:8000 and the platform at http://localhost:8000/platform/login.

| Who | Email | Password | PIN |
| --- | --- | --- | --- |
| Platform admin | admin@makerly.test | Makerly#2026 | |
| Acme owner | owner@acme.test | Makerly#2026 | 2468 |
| Cashier | cashier@acme.test | Makerly#2026 | 1234 |
| Production | production@acme.test | Makerly#2026 | 2345 |
| Store | store@acme.test | Makerly#2026 | 3456 |

Prices are Tanzanian shillings. The workspace clock is Africa/Dar_es_Salaam. Switch the interface between English and Kiswahili from the top bar.

## Scheduled jobs

`php artisan schedule:work` runs feature-expiry, stock reconciliation, tenant purge after the retention window, and low-stock notices.
