# Nexora – Modern E-Commerce Platform

A modern, responsive e-commerce application built with **PHP 8+** and **PostgreSQL**. Features a product catalog across **20 categories (240+ products)**, real-time **Product Delivery Tracking**, **Transaction History**, **User Authentication**, **AI Shopping Assistant**, and official **PayPal Sandbox Checkout** with instant order cancellation refunds.

---

## 🌟 Key Features

1. **20 Product Categories & 240+ Items** (12 products each):
   - 📱 Electronics (Smartphones, tablets, power banks, chargers)
   - 💻 Computers & Laptops (Laptops, desktops, monitors, keyboards, mice)
   - 👕 Fashion & Clothing (T-shirts, shirts, jeans, dresses, jackets)
   - 👟 Footwear (Sneakers, boots, loafers, sandals, slippers)
   - ⌚ Watches & Accessories (Smartwatches, analog watches, belts, wallets)
   - 💄 Beauty & Personal Care (Makeup, skincare, perfumes, hair dryers)
   - 🏠 Home & Kitchen (Cookware, appliances, bedsheets, storage)
   - 🛋️ Furniture (Sofas, chairs, tables, beds, wardrobes)
   - 📚 Books & Stationery (Books, notebooks, pens, organizers)
   - 🧸 Toys & Games (Board games, puzzles, action figures, dolls)
   - 🏋️ Sports & Fitness (Dumbbells, yoga mats, cricket bats, bicycles)
   - 🥫 Grocery & Food (Snacks, beverages, packaged foods, staples)
   - 👶 Baby Products (Diapers, clothes, toys, strollers, bottles)
   - 🐶 Pet Supplies (Pet food, toys, grooming products, accessories)
   - 🚗 Automotive Accessories (Car chargers, seat covers, helmets, cleaners)
   - 🎮 Gaming (Consoles, controllers, gaming keyboards, headsets, VR)
   - 🎧 Audio & Accessories (Headphones, earbuds, speakers, microphones)
   - 👜 Bags & Luggage (Backpacks, handbags, suitcases, travel bags)
   - 💍 Jewellery (Rings, necklaces, earrings, bracelets)
   - 🩺 Health & Wellness (Thermometers, fitness trackers, monitors, massagers)

2. **PostgreSQL Database Integration**:
   - `categories`: Category names, slugs, emojis, and descriptions.
   - `products`: Product details, categories, prices, badges, ratings, reviews, and stock.
   - `customers`: Customer profiles and shipping addresses.
   - `orders`: Order headers, totals, statuses, customer references, and PayPal tracking IDs.
   - `order_items`: Line items, prices, and quantities per order.
   - `product_deliveries`: Carrier information (FedEx, DHL, BlueDart, UPS), tracking numbers (`TRK-...`), delivery statuses, and estimated delivery dates.
   - `delivery_timeline`: Step-by-step transit tracking checkpoints and locations.
   - `transactions`: Product transaction history (`TXN-...`), payment methods (Credit Card, PayPal, UPI, Apple Pay), amounts, and gateway responses.

3. **Live Product Delivery Tracker (`track_delivery.php`)**:
   - Search by Tracking Number or Order Number.
   - Visual multi-step progress bar: *Order Confirmed ➔ Processing ➔ In Transit ➔ Out for Delivery ➔ Delivered*.
   - Checkpoint timeline showing timestamps, hub locations, and courier notes.

4. **Product Transaction History (`transactions.php`)**:
   - Financial KPI cards: Total processed volume, successful payments count, pending count, and average order value.
   - Search and filter by status (*Success*, *Pending*, *Failed*, *Refunded*) and payment method.

5. **User Authentication & Security**:
   - Secure Registration and Login with password strength enforcement (12+ characters, upper, lower, numbers, symbols).
   - Session fixation protection, CSRF token verification, and brute-force lockout throttling.

6. **Official PayPal Sandbox Checkout & Order Cancellation**:
   - Official PayPal Smart Buttons SDK with backend order creation and payment capture.
   - Zero hardcoded secrets; Client Secret strictly backend-isolated.
   - Instant order cancellation with automated PayPal REST API refund.

---

## 🗄️ PostgreSQL Setup Guide

### Option 1: Docker (Fastest — 5 seconds)

Run the official PostgreSQL container:
```bash
docker run --name pg-ecommerce -e POSTGRES_PASSWORD=your_secure_password_here -e POSTGRES_DB=ecommerce_db -p 5432:5432 -d postgres
```

Then visit **http://localhost:8000/database_setup.php** and click **"Initialize & Seed PostgreSQL Database"**.

### Option 2: Local PostgreSQL / pgAdmin

1. Ensure PostgreSQL is installed and listening on port `5432`.
2. Copy `.env.example` to `.env` and set your database credentials:
   ```env
   DB_HOST=localhost
   DB_PORT=5432
   DB_NAME=ecommerce_db
   DB_USER=postgres
   DB_PASS=your_secure_password_here
   ```
3. Initialize tables and seed data via CLI:
   ```bash
   php database/setup.php
   ```
   *Or import directly via psql:*
   ```bash
   psql -U postgres -d ecommerce_db -f database/schema.sql
   psql -U postgres -d ecommerce_db -f database/seeds.sql
   psql -U postgres -d ecommerce_db -f database/migrations/001_create_auth_tables.sql
   psql -U postgres -d ecommerce_db -f database/migrations/002_add_paypal_and_cancellation.sql
   ```

---

## 🚀 How to Run the Web Application

Start PHP's built-in web server:

```bash
cd ecommerce-demo
php -S localhost:8000
```

Open your browser and navigate to:
- **Shop / Catalog**: [http://localhost:8000/](http://localhost:8000/)
- **Delivery Tracking**: [http://localhost:8000/track_delivery.php](http://localhost:8000/track_delivery.php)
- **Transaction History**: [http://localhost:8000/transactions.php](http://localhost:8000/transactions.php)
- **Database Management**: [http://localhost:8000/database_setup.php](http://localhost:8000/database_setup.php)
- **Shopping Cart**: [http://localhost:8000/cart.php](http://localhost:8000/cart.php)
- **My Account**: [http://localhost:8000/account.php](http://localhost:8000/account.php)

---

## 🤖 Nexora AI Assistant (Chatbot)

Nexora features a floating AI shopping assistant with native Function Calling tools and PostgreSQL integration. The widget appears automatically on every page at the bottom-right corner.

### Dual Capabilities

1. **AI Advisor (General Shopping & Gift Intelligence):**
   - Answers product queries, comparisons, and buying tips (e.g. *"Which laptop is best for coding and gaming?"*, *"Which cookware should I buy?"*).
   - Handles gift recommendations by asking follow-up questions (occasion, recipient interests, budget) and recommending store products.
2. **AI Store Assistant (Function Calling / Database Tools):**
   - 🔍 `search_products(keyword, category, max_price)`: Real-time parameterized search across 240+ products.
   - 🚚 `track_order(tracking_number_or_order_number)`: Queries live delivery status and courier timeline checkpoints.
   - 💳 `get_transactions(order_number_or_transaction_id)`: Checks payment gateway records and receipt status.
   - 🛒 `add_to_cart(product_id, quantity)`: Directly adds recommended products to the cart with dynamic badge updates.
   - 🛡️ **Store Policy & PayPal Support**: Instant answers for PayPal Express Checkout, 30-day money-back guarantee, and payment options.

### Setup Instructions

Nexora AI Assistant uses **OpenRouter** with **Automatic Model Selection** (`auto`) as the primary LLM provider, and features an integrated offline-resilient intent detection and typo correction engine (`chatbot_intent.php`):

#### OpenRouter Configuration (Recommended)
1. Obtain an API key from [OpenRouter](https://openrouter.ai/keys) (starts with `sk-or-...`).
2. Add the configuration to your `.env` file:
   ```env
   OPENROUTER_API_KEY=your_openrouter_api_key_here
   OPENROUTER_MODEL=auto
   LLM_PROVIDER=openrouter
   ```

#### Offline / Fallback Resiliency
Even without an active internet connection or API credits, the built-in **Intelligent Intent & Typo Correction Engine** (`chatbot_intent.php`) guarantees:
- **Deterministic Typo Correction:** e.g., *"lptop"* ➔ *"laptop"*, *"wtch"* ➔ *"watch"*, *"samsng"* ➔ *"Samsung"*, *"headfones"* ➔ *"headphones"*.
- **Category Isolation:** Requesting a laptop only returns laptops (no mice, keyboards, or monitors); requesting a watch only returns watches.
- **Natural Budget & Multi-Turn Context:** Handles *"under 40000"*, *"laptop under 40000"*, and retains previous turn context during multi-turn shopping chats.
- **Accurate Out-of-Catalog Handling:** Queries for non-existent categories return zero products with a courteous explanatory message.

---

## 🅿️ PayPal Sandbox Integration & Order Cancellation

Nexora integrates the official **PayPal Checkout v2 REST API** with backend order capture and instant cancellation refunds:

1. Obtain your sandbox credentials from [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications/sandbox).
2. Add your credentials to `.env`:
   ```env
   PAYPAL_CLIENT_ID=your_paypal_sandbox_client_id_here
   PAYPAL_CLIENT_SECRET=your_paypal_sandbox_client_secret_here
   PAYPAL_ENVIRONMENT=sandbox
   ```
3. Test using a **Personal (Buyer) Sandbox Account** from PayPal's Testing Tools dashboard.
4. Eligible placed orders can be cancelled with one click from `account.php` or `track_delivery.php`, triggering automatic REST API refunds for captured payments.

---

## 📁 Project Structure

```
ecommerce-demo/
├── chat_api.php          → AI chat endpoint with function calling & tools
├── chat_widget.php       → Floating chatbot widget (HTML + Vanilla JS, zero dependencies)
├── chatbot_intent.php    → Intent detection, typo correction, fuzzy matching & SQL query engine
├── css/
│   ├── chat.css          → Chat window, bubbles, product cards & typing animation
│   └── style.css         → Complete modern styling
├── auth.php              → Core authentication, password hashing, CSRF & rate limiting
├── signup.php            → Secure registration with live 12+ char password meter
├── login.php             → User authentication with brute-force lockout & remember me
├── logout.php            → POST-only CSRF-verified session destruction
├── account.php           → Customer dashboard with profile, orders & shipment links
├── forgot_password.php   → Password reset request with enumeration protection
├── reset_password.php    → Secure token validation & new password creation
├── paypal_api.php        → Secure AJAX endpoint for PayPal Smart Buttons
├── paypal_service.php    → PayPal REST API v2 client (OAuth, orders, captures, refunds)
├── cancel_order.php      → CSRF-protected order cancellation & refund handler
├── index.php             → Products catalog with 20 categories & live filters
├── track_delivery.php    → Shipment tracker with visual stepper & transit checkpoints
├── transactions.php      → Product transaction history & financial KPI metrics
├── checkout.php          → Checkout with PayPal Smart Buttons & shipping form
├── cart.php              → Shopping cart with quantity controls
├── cart_action.php       → Cart add/remove/adjust actions
├── database_setup.php    → Web-based PostgreSQL installer and live status panel
├── config.php            → Database, LLM and application configurations
├── db.php                → PDO PostgreSQL database connection and query helpers
├── orders_service.php    → Service layer for orders, deliveries & transactions
├── products.php          → Product repository with dynamic DB query & fallback
├── products_data.php     → Master catalog data (20 categories, 240 products)
├── header.php            → Header with navigation, cart badge & user profile
├── footer.php            → Footer with navigation and embedded AI chatbot widget
├── tests/
│   ├── auth_test.php              → Automated test suite verifying 16 security & auth criteria
│   ├── chatbot_test.php           → Automated test suite verifying all chatbot capabilities
│   ├── test_paypal_and_cancel.php → Automated test suite verifying Scenarios A through L
│   └── test_recommendations.php   → Automated test suite verifying 14 multi-turn scenarios
└── database/
    ├── migrations/       → SQL migrations (auth tables, remember tokens, orders.paypal_*)
    ├── schema.sql        → PostgreSQL DDL (tables, indexes, foreign keys)
    ├── seeds.sql         → Pre-seeded data (240 products, deliveries, transactions)
    └── setup.php         → Automated installer script
```
