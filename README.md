# Flash Sale Checkout API

A high-performance, concurrency-safe Flash Sale Checkout API built with Laravel 12 and MySQL. This system handles high-traffic flash sales with temporary stock holds, order creation, and idempotent payment webhooks.

## 🎯 Features

- **Concurrency-Safe Stock Management**: Prevents overselling even under extreme load using database row-level locking (`SELECT FOR UPDATE`) and cache locks
- **Temporary Stock Holds**: 2-minute holds that automatically expire and release stock
- **Idempotent Payment Webhooks**: Prevents duplicate processing of payment webhooks
- **Out-of-Order Webhook Handling**: Safely handles webhooks that arrive before orders are created
- **Automatic Hold Expiration**: Background job runs every 30 seconds to expire holds
- **Real-Time Stock Calculation**: Accurate available stock calculation with caching
- **Comprehensive Testing**: Full test coverage including concurrency, expiry, and idempotency tests

## 📋 Requirements

- PHP 8.2+
- Laravel 12
- MySQL (InnoDB engine)
- Redis (recommended) or Database cache driver
- Composer

## 🚀 Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd Task
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Update `.env` file**
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=task
   DB_USERNAME=root
   DB_PASSWORD=
   
   CACHE_DRIVER=redis  # or database
   QUEUE_CONNECTION=database
   ```

5. **Run migrations and seeders**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

6. **Start the scheduler** (for automatic hold expiration)
   ```bash
   php artisan schedule:work
   ```

## 📡 API Endpoints

### 1. Get Product Details
```http
GET /api/products/{id}
```

**Response:**
```json
{
  "id": 1,
  "name": "Flash Sale Product",
  "price": 99.99,
  "total_stock": 100,
  "available_stock": 95
}
```

### 2. Create Hold (Temporary Stock Reservation)
```http
POST /api/holds
Content-Type: application/json

{
  "product_id": 1,
  "qty": 2
}
```

**Response (201):**
```json
{
  "hold_id": 123,
  "expires_at": "2025-11-28T20:02:00Z"
}
```

**Error (409):**
```json
{
  "error": "Insufficient stock or unable to create hold"
}
```

### 3. Create Order
```http
POST /api/orders
Content-Type: application/json

{
  "hold_id": 123
}
```

**Response (201):**
```json
{
  "order_id": 456,
  "status": "pending_payment",
  "product_id": 1,
  "qty": 2
}
```

### 4. Payment Webhook
```http
POST /api/payments/webhook
Content-Type: application/json

{
  "idempotency_key": "payment_123_abc",
  "order_id": 456,
  "status": "paid",
  "provider_payload": {
    "transaction_id": "txn_789",
    "amount": 199.98
  }
}
```

**Response (200):**
```json
{
  "message": "Webhook processed",
  "order_id": 456,
  "status": "paid"
}
```

## 🔒 Concurrency Safety

The system uses multiple layers of protection to prevent overselling:

1. **Database Row-Level Locking**: `SELECT FOR UPDATE` locks product rows during stock checks
2. **Cache Locks**: Distributed locks prevent concurrent access at the application level
3. **Database Transactions**: All stock operations are atomic
4. **Double-Check Locking**: Prevents race conditions in background jobs

### Stock Calculation Formula
```
available_stock = total_stock - active_holds_qty - paid_orders_qty
```

## 🧪 Testing

Run the test suite:
```bash
php artisan test
```

### Test Coverage

- **Stock Boundary Concurrency**: Tests 10 parallel hold requests with stock=5, ensures only 5 succeed
- **Hold Expiry**: Verifies expired holds release stock correctly
- **Webhook Idempotency**: Ensures duplicate webhooks are not processed
- **Out-of-Order Webhooks**: Handles webhooks arriving before orders are created
- **Cancelled Orders**: Verifies stock is released when orders are cancelled

## 📊 Database Schema

### Products Table
- `id`, `name`, `price`, `total_stock`, `timestamps`

### Holds Table
- `id`, `product_id`, `qty`, `expires_at`, `used_at`, `status` (active/expired/used), `timestamps`

### Orders Table
- `id`, `hold_id`, `product_id`, `qty`, `status` (pending_payment/paid/cancelled), `payment_meta` (JSON), `timestamps`

### Webhook Idempotency Table
- `id`, `idempotency_key` (unique), `processed_at`, `payload_hash`, `result_state`, `timestamps`

## 🔄 Background Jobs

### ExpireHoldsJob
- **Frequency**: Every 30 seconds
- **Purpose**: Automatically expire holds that have passed their expiration time
- **Safety**: Uses `lockForUpdate()` to prevent double-processing
- **Performance**: Processes in batches of 100

## 📝 Logging

The system logs:
- Lock contention and retries
- Hold creation and expiration
- Webhook idempotency hits
- Order status changes
- Stock availability changes

## 🛠️ Architecture

### Key Components

- **StockService**: Handles all stock-related operations with concurrency safety
- **Controllers**: API endpoints for products, holds, orders, and webhooks
- **Models**: Eloquent models with relationships and helper methods
- **Jobs**: Background processing for hold expiration
- **Migrations**: Database schema definitions

## 📚 Documentation

See `FLOW_EXPLANATION.md` for detailed flow diagrams and explanations in Arabic.

## ⚠️ Important Notes

1. **Cache**: Stock availability is cached for 5 seconds. Cache is cleared on any stock changes.
2. **Hold Duration**: Holds expire after 2 minutes automatically.
3. **Idempotency**: Webhooks with the same `idempotency_key` are only processed once.
4. **Out-of-Order**: Webhooks can arrive before orders are created - the system handles this gracefully.

## 🚦 Usage Example

```bash
# 1. Check product availability
curl http://localhost:8000/api/products/1

# 2. Create a hold
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 2}'

# 3. Create an order
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 123}'

# 4. Process payment webhook
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "payment_123",
    "order_id": 456,
    "status": "paid",
    "provider_payload": {"transaction_id": "txn_789"}
  }'
```

## 📄 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
