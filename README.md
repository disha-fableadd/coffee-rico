# WhatsApp Bulk Messaging Laravel API

This is a Laravel 10 API conversion of the Node.js WhatsApp bulk messaging application, featuring Passport authentication and comprehensive bulk messaging functionality.

## Features

- **Authentication**: Laravel Passport OAuth2 authentication
- **User Management**: Registration, login, profile management
- **Contact Management**: CRUD operations for contacts
- **Group Management**: Organize contacts into groups
- **Template Management**: Create and manage message templates
- **Bulk Messaging**: Send messages to multiple contacts
- **Package System**: Subscription-based messaging limits
- **Credit System**: Track and manage messaging credits
- **Reports**: Analytics and reporting features

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/bhavvvikfab/whatsapp-bulk-laravel.git
   cd whatsapp-bulk-laravel
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Environment Setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database Configuration**
   Update your `.env` file with database credentials:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=whatsapp_bulk_laravel
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

5. **Run Migrations**
   ```bash
   php artisan migrate
   ```

6. **Install Passport**
   ```bash
   php artisan passport:install
   ```

7. **Start Development Server**
   ```bash
   php artisan serve
   ```

## Post-setup: Admin user and packages

API base path is `/api` (for example `http://127.0.0.1:8000/api` when using `php artisan serve`). Protected routes need a Passport token from login or registration.

### Create an admin user

Users have a `role` of `user` or `admin`. Choose one approach:

1. **Register as admin (no token required)**  
   `POST /api/auth/register` with JSON body including `"role": "admin"` (optional fields default to `user`). Required: `name`, `email`, `number`, `password` (min 8 characters).  
   **Security:** In production you should not leave open registration with `role: admin` unless you trust the network; prefer promoting the first user in the database or creating admins only from an already-authenticated admin.

2. **Promote an existing user** (after migrations and a normal registration):
   ```bash
   php artisan tinker
   ```
   ```php
   \App\Models\User::where('email', 'you@example.com')->update(['role' => 'admin']);
   ```

3. **Another admin creates a user**  
   `POST /api/users/add` with header `Authorization: Bearer {token}` and an admin account. Body: `name`, `email`, `password`, `number`, optional `role` (`user` or `admin`), optional `status` (`active`, `inactive`, `pending`; default `active`).

### Log in

`POST /api/auth/login` with `email` and `password`. The JSON response includes `token` — use it as:

```
Authorization: Bearer {token}
```

Users must have `status` `active` to log in.

### Browse and manage package definitions

| Action | Method | Endpoint | Who |
|--------|--------|----------|-----|
| List all packages | `GET` | `/api/package/get` | Any authenticated user |
| Get one package | `GET` | `/api/package/get/{id}` | Any authenticated user |
| Create package | `POST` | `/api/package/add` | **Admin only** |
| Update package | `PUT` | `/api/package/edit/{id}` | **Admin only** |
| Delete package | `DELETE` | `/api/package/delete/{id}` | **Admin only** |

**Create package (admin)** — example JSON body:

```json
{
  "packageName": "Starter",
  "packageDesc": "Monthly starter plan",
  "day": 30,
  "msgCount": 1000,
  "templateCount": 10
}
```

The `packages` table also supports `contactCount` when present; include it in the JSON if your app expects contact limits on the plan.

### Select / attach a package for a user (active plan)

A user may have **only one active subscription** at a time (`status` = 1). If the API responds that an active package already exists, resolve that before assigning another.

| Action | Method | Endpoint | Body / notes |
|--------|--------|----------|----------------|
| Activate plan for yourself | `POST` | `/api/plan/create` | `packageId` (required). Admins may also send `userId` to activate for another user. |
| Assign plan to a user | `POST` | `/api/plan/assign` | **Admin only.** `userId`, `packageId`. |
| List active plans (global) | `GET` | `/api/plan/user/{userId}` | Returns active packages; behavior is global in the controller. |
| Plan history | `GET` | `/api/plan/history/{userId}` | History-style list. |
| Renewal info | `GET` | `/api/plan/renewal-status` | Renewal / usage snapshot for the latest active plan. |
| Renew plan | `POST` | `/api/plan/renew/{id}` | `{ "password": "..." }` — uses `RENEW_PLAN_PASSWORD` from `.env` (default in code: `renew123`). **`{id}`** is the **active package** row id, not the Laravel user id. |

**Example: list packages, then subscribe as the logged-in user**

```bash
curl -s -H "Authorization: Bearer YOUR_TOKEN" http://127.0.0.1:8000/api/package/get
```

```bash
curl -s -X POST -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d "{\"packageId\": 1}" \
  http://127.0.0.1:8000/api/plan/create
```

**Example: admin assigns package 2 to user 5**

```bash
curl -s -X POST -H "Authorization: Bearer ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d "{\"userId\": 5, \"packageId\": 2}" \
  http://127.0.0.1:8000/api/plan/assign
```

## API Endpoints

### Authentication
- `POST /api/auth/register` - Register new user
- `POST /api/auth/login` - Login user
- `GET /api/auth/profile` - Get user profile
- `PUT /api/auth/profile` - Update user profile
- `PUT /api/auth/change-password` - Change password
- `POST /api/auth/logout` - Logout user

### Contacts
- `GET /api/contacts` - Get all contacts
- `POST /api/contacts` - Create new contact
- `GET /api/contacts/{id}` - Get specific contact
- `PUT /api/contacts/{id}` - Update contact
- `DELETE /api/contacts/{id}` - Delete contact

### Packages and active plans

See [Post-setup: Admin user and packages](#post-setup-admin-user-and-packages) for admin creation, request bodies, and curl examples.

- **Packages:** `GET /api/package/get`, `GET /api/package/get/{id}`, `POST /api/package/add`, `PUT /api/package/edit/{id}`, `DELETE /api/package/delete/{id}` (create/update/delete: admin only)
- **Active plans:** `POST /api/plan/create`, `POST /api/plan/assign` (admin), `GET /api/plan/user/{userId}`, `GET /api/plan/history/{userId}`, `GET /api/plan/renewal-status`, `POST /api/plan/renew/{id}`

### Users (admin)

- `GET /api/users` - List users (admin)
- `POST /api/users/add` - Create user, optional `role` / `status` (admin)

## Authentication

All protected routes require a Bearer token in the Authorization header:
```
Authorization: Bearer {your_access_token}
```

## Database Structure

### Core Models
- **Users**: User accounts with company information
- **Contacts**: Contact management with tags and groups
- **Groups**: Contact grouping functionality
- **Templates**: Message templates with variables
- **BulkMessages**: Bulk messaging campaigns
- **Packages**: Subscription packages
- **ActivePackages**: User package subscriptions
- **Credits**: Credit tracking system
- **Settings**: Application settings

## Key Differences from Node.js Version

1. **Authentication**: Uses Laravel Passport instead of JWT
2. **Database**: MySQL/PostgreSQL instead of MongoDB
3. **ORM**: Eloquent ORM instead of Mongoose
4. **Validation**: Laravel validation instead of manual validation
5. **File Uploads**: Laravel file handling instead of Multer
6. **API Structure**: RESTful API with resource controllers

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## License 

This project is proprietary software developed by **Fablead Developers Technolab**. All rights reserved.
