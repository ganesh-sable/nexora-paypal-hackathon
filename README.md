# Nexora AI E-Commerce Platform

A production-ready, intelligent e-commerce platform built with **PHP 8+** and **PostgreSQL**. Features a product catalog across **20 categories (240+ products)**, an **AI Shopping Assistant** powered by OpenRouter, official **PayPal Sandbox Checkout** with automated refund cancellation, real-time **Product Delivery Tracking**, and **Transaction History**.

Designed for **zero-cost cloud deployment** using **Render** (Docker Web Service) and **Neon** (Serverless PostgreSQL Cloud Database).

---

## 🌟 Architecture & Tech Stack

```
                              ┌──────────────────────────────────────┐
                              │           Client Browser             │
                              │     (HTML5 / CSS3 / Vanilla JS)      │
                              └──────────────────┬───────────────────┘
                                                 │
                                                 │ HTTPS / WSS
                                                 ▼
┌────────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                Render Cloud Platform (Free Tier)                                   │
│  ┌──────────────────────────────────────────────────────────────────────────────────────────────┐  │
│  │                              Docker Container (php:8.2-apache)                               │  │
│  │                                                                                              │  │
│  │   Apache 2.4 (Dynamic Port ${PORT:-10000}) ➔ /var/www/html                                  │  │
│  │   ├── Frontend Views: index.php, cart.php, checkout.php, track_delivery.php                  │  │
│  │   ├── Core APIs: chat_api.php, paypal_api.php, health.php                                   │  │
│  │   ├── Business Services: orders_service.php, paypal_service.php, auth.php                    │  │
│  │   └── Database Layer: db.php (PDO PostgreSQL with SSL & cold-start retry)                   │  │
│  └──────────────────────────────┬───────────────────────────────┬───────────────────────────────┘  │
└─────────────────────────────────┼───────────────────────────────┼──────────────────────────────────┘
                                  │                               │
                                  ▼                               ▼
       ┌────────────────────────────────────┐   ┌───────────────────────────────────┐
       │     Neon Cloud PostgreSQL (Free)   │   │         External APIs             │
       │     - Serverless Postgres          │   │  - OpenRouter AI (LLM auto model) │
       │     - sslmode=require              │   │  - PayPal REST API v2 (Sandbox)   │
       │     - 20 Categories / 240 Products │   └───────────────────────────────────┘
       └────────────────────────────────────┘
```

- **Backend Runtime:** PHP 8.2+ with `pdo_pgsql`, `pgsql`, `opcache`, `curl`, `json`, `mbstring`.
- **Database:** PostgreSQL (Neon serverless cloud or local PostgreSQL 15/16).
- **Web Server:** Apache 2.4 with `mod_rewrite`, `mod_headers`, and dynamic `$PORT` binding for container runtimes.
- **AI Engine:** OpenRouter API (`auto` model routing) with local heuristic typo correction fallback.
- **Payment Gateway:** PayPal REST API v2 (Orders, Captures, Instant Refunds).
- **Hosting & Infrastructure:** Render Free Web Service + Neon Free Serverless PostgreSQL.
- **Containerization:** Docker (`Dockerfile`) and Docker Compose (`docker-compose.yml`).

---

## 🚀 Live Cloud Deployment Guide (Zero Cost)

Follow these steps to deploy Nexora to the cloud for free using **Render** and **Neon**.

### Step 1: Provision a Free PostgreSQL Database on Neon

1. Visit [Neon.tech](https://neon.tech/) and sign up for a free account.
2. Click **Create Project**, name it `nexora-db`, select your preferred region (e.g. `US East (Ohio)` or `EU (Frankfurt)`), and click **Create Project**.
3. In your Neon dashboard, locate the **Connection Details** box.
4. Copy the **Connection string** (URI format with pooling enabled):
   ```text
   postgresql://neondb_owner:YOUR_PASSWORD@ep-sample-pooler.us-east-2.aws.neon.tech/neondb?sslmode=require
   ```
5. *(Optional)* Run schema migrations directly from your local terminal or Neon's SQL Editor:
   - In Neon Dashboard: Go to **SQL Editor**, paste the contents of `database/schema.sql`, and execute it. Then paste and execute `database/seeds.sql`.
   - *Or* run the CLI installer locally pointing to your Neon database:
     ```bash
     DATABASE_URL="your-neon-connection-string" php database/setup.php
     ```

---

### Step 2: Deploy Application on Render

Render supports Docker web services on its free tier.

#### Method A: Using `render.yaml` Blueprint (One-Click)

1. Push your code to your GitHub repository:
   ```bash
   git push origin main
   ```
2. Log in to [Render.com](https://render.com/).
3. Click **New +** ➔ **Blueprint**.
4. Select your `nexora-paypal-hackathon` repository.
5. Render will automatically detect `render.yaml`.
6. Fill in the required environment variables in the prompt:
   - `DATABASE_URL`: Your Neon connection string (from Step 1).
   - `PAYPAL_CLIENT_ID`: Your PayPal Sandbox Client ID.
   - `PAYPAL_CLIENT_SECRET`: Your PayPal Sandbox Secret.
   - `OPENROUTER_API_KEY`: Your OpenRouter API Key.
7. Click **Apply**. Render will build the Docker container and deploy the app!

#### Method B: Manual Web Service Setup

1. Log in to [Render.com](https://render.com/).
2. Click **New +** ➔ **Web Service**.
3. Connect your GitHub repository.
4. Configure the settings:
   - **Name:** `nexora-ai-ecommerce`
   - **Region:** Same region as your Neon database (e.g. `Oregon (US West)` or `Ohio (US East)`).
   - **Runtime:** `Docker`
   - **Dockerfile Path:** `./Dockerfile`
   - **Instance Type:** `Free` (0.1 CPU, 512 MB RAM)
5. Scroll down to **Environment Variables** and add:
   | Key | Value | Description |
   | :--- | :--- | :--- |
   | `APP_ENV` | `production` | Enables production error logging and security checks |
   | `DATABASE_URL` | `postgresql://...` | Neon PostgreSQL connection string (`?sslmode=require`) |
   | `PAYPAL_CLIENT_ID` | `your_sandbox_client_id` | PayPal Sandbox REST App Client ID |
   | `PAYPAL_CLIENT_SECRET`| `your_sandbox_secret` | PayPal Sandbox REST App Secret |
   | `PAYPAL_ENVIRONMENT` | `sandbox` | PayPal API environment |
   | `OPENROUTER_API_KEY` | `sk-or-v1-...` | OpenRouter API Key for AI Assistant |
   | `OPENROUTER_MODEL` | `auto` | Auto routes to optimal AI models |
6. Set **Health Check Path** to `/health.php`.
7. Click **Create Web Service**.
8. Once deployment completes, your application will be live at `https://nexora-ai-ecommerce.onrender.com`!

---

## 🐳 Local Development with Docker

To run the complete application and a dedicated PostgreSQL database locally using Docker Compose:

### 1. Clone & Configure
```bash
git clone https://github.com/ganesh-sable/nexora-paypal-hackathon.git
cd nexora-paypal-hackathon

# Copy sample environment configuration
cp .env.example .env
```
Fill in your `OPENROUTER_API_KEY`, `PAYPAL_CLIENT_ID`, and `PAYPAL_CLIENT_SECRET` in `.env`.

### 2. Start Services
```bash
docker compose up --build -d
```

Docker will:
1. Spin up a `postgres:15-alpine` container, automatically executing `schema.sql` and `seeds.sql`.
2. Build the PHP 8.2 Apache container with `pdo_pgsql` and OPcache.
3. Bind the application to port `8000`.

### 3. Open Application
Navigate to [http://localhost:8000](http://localhost:8000).

To stop the containers:
```bash
docker compose down
```

---

## 💻 Local Development (Native PHP CLI)

If you have PHP 8.2+ installed locally with `pdo_pgsql`:

1. Copy `.env.example` to `.env` and configure your credentials.
2. Initialize the database:
   ```bash
   php database/setup.php
   ```
3. Start the PHP built-in web server:
   ```bash
   php -S localhost:8000
   ```
4. Access the web store at [http://localhost:8000](http://localhost:8000).

---

## ⚙️ Environment Variables Reference

| Variable | Required | Default | Description |
| :--- | :---: | :---: | :--- |
| `APP_ENV` | Optional | `development` | Set to `production` in live deployments to disable error printing. |
| `DATABASE_URL` | **Yes (Cloud)** | - | Full PostgreSQL connection URI (`postgresql://user:pass@host:5432/db?sslmode=require`). |
| `DB_HOST` | Local only | `localhost` | PostgreSQL hostname (ignored if `DATABASE_URL` is set). |
| `DB_PORT` | Local only | `5432` | PostgreSQL port. |
| `DB_NAME` | Local only | `ecommerce_db` | PostgreSQL database name. |
| `DB_USER` | Local only | `postgres` | PostgreSQL username. |
| `DB_PASS` | Local only | - | PostgreSQL password. |
| `DB_SSLMODE` | Optional | `require` (cloud) | `require` for Neon/cloud, `disable` for local dev. |
| `PORT` | Container | `10000` | HTTP listening port (automatically set by Render). |
| `PAYPAL_CLIENT_ID` | **Yes** | - | PayPal REST API Client ID from Developer Dashboard. |
| `PAYPAL_CLIENT_SECRET` | **Yes** | - | PayPal REST API Secret Key (kept secure on backend). |
| `PAYPAL_ENVIRONMENT` | Optional | `sandbox` | `sandbox` for testing, `live` for production. |
| `OPENROUTER_API_KEY` | **Yes** | - | OpenRouter API Key for AI features. |
| `OPENROUTER_MODEL` | Optional | `auto` | LLM model (`auto` selects the best cost-effective model). |

---

## 🏥 Health Check & Monitoring

Nexora includes an unauthenticated, zero-credential-leak health check endpoint:

**Endpoint:** `GET /health.php`

**Sample Response:**
```json
{
    "status": "ok",
    "app": "Nexora",
    "version": "2.0.0",
    "environment": "production",
    "timestamp": "2026-11-03T12:00:00Z",
    "services": {
        "database": {
            "status": "connected",
            "driver": "pdo_pgsql"
        },
        "ai_assistant": {
            "provider": "openrouter",
            "configured": true
        },
        "payments": {
            "gateway": "paypal",
            "mode": "sandbox",
            "configured": true
        }
    }
}
```

---

## 🧪 Automated Test Suites

The codebase includes comprehensive automated tests covering security, chatbot intent, payments, and gift recommendations:

```bash
# Run security & authentication test suite (16 tests)
php tests/auth_test.php

# Run AI chatbot & tool calling test suite
php tests/chatbot_test.php

# Run PayPal Sandbox & Order Cancellation test suite
php tests/test_paypal_and_cancel.php

# Run multi-turn AI recommendation test suite (14 scenarios)
php tests/test_recommendations.php
```

All 4 test suites run without external dependencies and execute in seconds.

---

## 🔒 Security Best Practices Implemented

- **Credential Isolation:** No credentials, API tokens, or secrets are committed to the repository. All secrets are loaded from environment variables.
- **Render Port & Proxy Support:** Secure HTTPS cookie flags support `X-Forwarded-Proto: https` from Render's edge load balancer.
- **SQL Injection Prevention:** 100% of database interactions utilize prepared statements with bound parameters (`PDO`).
- **CSRF Defense:** Cryptographic constant-time CSRF tokens on all state-changing POST requests (`login.php`, `signup.php`, `checkout.php`, `cancel_order.php`, `database_setup.php`).
- **Brute Force Defense:** Account lockout after 5 consecutive failed login attempts with progressive throttling.
- **Password Hashing:** Passwords hashed using standard `password_hash()` with `PASSWORD_DEFAULT` (bcrypt/argon2). Plaintext passwords are never stored or logged.
- **Safe PayPal Integration:** Client Secret is strictly retained on the backend; only the public Client ID is surfaced to the frontend SDK.
