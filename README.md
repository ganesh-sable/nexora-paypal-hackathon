# Nexora – Modern E-Commerce Platform

A modern, responsive e-commerce application built with **PHP 8+** and **PostgreSQL**. Features a product catalog across **20 categories (240+ products)**, real-time **Product Delivery Tracking**, **Transaction History**, and interactive database diagnostics.

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
   - `orders`: Order headers, totals, statuses, and customer references.
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
   - Search and filter by status (*Success*, *Pending*, *Failed*) and payment method.

5. **Seamless Standalone & Hybrid Fallback**:
   - If PostgreSQL is still launching or offline, ShopDemo runs safely in catalog fallback mode without breaking. Once PostgreSQL is connected, all records are stored directly into PostgreSQL.

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

---

## 📁 Project Structure

```
ecommerce-demo/
├── index.php             → Products catalog with 20 categories & live filters
├── track_delivery.php    → Shipment tracker with visual stepper & transit checkpoints
├── transactions.php      → Product transaction history & financial KPI metrics
├── checkout.php          → Checkout with order, delivery, and payment persistence
├── cart.php              → Shopping cart with quantity controls
├── cart_action.php       → Cart add/remove/adjust actions
├── database_setup.php    → Web-based PostgreSQL installer and live status panel
├── config.php            → Database and application configurations
├── db.php                → PDO PostgreSQL database connection and query helpers
├── orders_service.php    → Service layer for orders, deliveries & transactions
├── products.php          → Product repository with dynamic DB query & fallback
├── products_data.php     → Master catalog data (20 categories, 240 products)
├── header.php            → Header with real-time PostgreSQL status dot
├── footer.php            → Modern footer with catalog and database stats
├── css/
│   └── style.css         → Complete modern styling
└── database/
    ├── schema.sql        → PostgreSQL DDL (tables, indexes, foreign keys)
    ├── seeds.sql         → Pre-seeded data (240 products, deliveries, transactions)
    └── setup.php         → Automated installer script
```
