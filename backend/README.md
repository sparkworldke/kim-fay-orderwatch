# Kim-Fay OrderWatch — Laravel API Backend

Laravel 13 + Sanctum REST API for the OrderWatch frontend.

## Requirements

- PHP 8.2+
- Composer 2
- MySQL 8

## Setup

```bash
# From /backend
composer install
cp .env.example .env        # already copied during install
php artisan key:generate    # already done
# Edit .env: DB_DATABASE, DB_USERNAME, DB_PASSWORD
php artisan migrate
php artisan db:seed         # creates default admin users
```

## Base URL

```
http://localhost/kim-fay-orderwatch/backend/public/api
```

Set `VITE_API_BASE_URL` in the frontend `.env` to match.

## Auth Flow

All protected routes require a Bearer token from login.

```
POST /api/auth/login      { email, password }  → { token, user }
GET  /api/auth/me                               → { id, name, email, role }
POST /api/auth/logout                           → { message }
```

## Default Users (after seeding)

| Email                    | Password   | Role                      |
|--------------------------|------------|---------------------------|
| admin@fayshop.co.ke      | password   | Administrator             |
| csm@fayshop.co.ke        | password   | Customer Service Manager  |

## Available Endpoints

| Method | Path                     | Auth | Description          |
|--------|--------------------------|------|----------------------|
| POST   | /api/auth/login          | No   | Login                |
| GET    | /api/auth/me             | Yes  | Current user         |
| POST   | /api/auth/logout         | Yes  | Logout               |
| GET    | /api/dashboard/kpis      | Yes  | Dashboard KPIs       |
| GET    | /api/orders              | Yes  | List orders          |
| POST   | /api/orders              | Yes  | Create order         |
| GET    | /api/orders/{id}         | Yes  | Get order            |
| PUT    | /api/orders/{id}         | Yes  | Update order         |
| DELETE | /api/orders/{id}         | Yes  | Delete order         |
| GET    | /api/customers           | Yes  | List customers       |
| POST   | /api/customers           | Yes  | Create customer      |
| GET    | /api/customers/{id}      | Yes  | Get customer         |
| PUT    | /api/customers/{id}      | Yes  | Update customer      |
| DELETE | /api/customers/{id}      | Yes  | Delete customer      |

## CORS

Allowed origins (configured in `config/cors.php`):
- `http://localhost:5173` (Vite dev server)
- `http://localhost:3000`
- `http://localhost`
- `https://orderwatch.fayshop.co.ke`
