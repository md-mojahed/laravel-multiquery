# Mojahed MultiQuery — Laravel Package

Fire multiple MySQL queries in parallel using a binary. Dashboard stats that took 500ms now take 50ms.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- `msquery` binary installed on server

## Installation

```bash
composer require mojahed/multiquery
```

Auto-discovery registers everything. No manual setup needed.

## Binary Setup

Install the `msquery` binary on your server:

```bash
cp msquery-linux-amd64 /usr/local/bin/msquery
chmod +x /usr/local/bin/msquery
```

Add to `.env`:
```env
MULTIQUERY_BIN=/usr/local/bin/msquery
MULTIQUERY_TIMEOUT=30
```

## Publish Config (Optional)

```bash
php artisan vendor:publish --tag=multiquery-config
```

---

## Usage

### Basic — Raw SQL

```php
use Mojahed\Facades\MultiQuery;

[$users, $orders, $revenue] = MultiQuery::run([
    "SELECT COUNT(*) as total FROM users",
    "SELECT COUNT(*) as total FROM orders",
    "SELECT SUM(amount) as total FROM payments",
]);
```

### Eloquent Builder with mq()

```php
// mq() replaces terminal methods like get(), first(), count()
[$users, $orders] = MultiQuery::run([
    User::where('active', 1)->mq('get'),
    Order::where('status', 'pending')->mq('get'),
]);
```

### All Supported Modes

```php
[$users, $user, $count, $sum, $avg, $min, $max, $names, $email, $exists] = MultiQuery::run([
    User::where('active', 1)->mq('get'),             // Collection of Users
    User::where('id', 1)->mq('first'),               // single User instance or null
    Order::mq('count'),                              // integer
    Order::mq('sum', 'amount'),                      // float
    Order::mq('avg', 'amount'),                      // float
    Order::mq('min', 'amount'),                      // mixed (smallest value)
    Order::mq('max', 'amount'),                      // mixed (largest value)
    User::where('active', 1)->mq('pluck', 'name'),   // ['Mojahed', 'Rahim']
    User::where('id', 1)->mq('value', 'email'),      // single scalar value
    Order::where('status', 'pending')->mq('exists'), // boolean
]);
```

> **Note:** `count`, `sum`, `avg`, `min`, and `max` modes automatically rewrite the query to use the proper SQL aggregate function. You don't need to write `selectRaw('COUNT(*)')` yourself — just pass the mode and column.

### Aggregates with GROUP BY

When using `groupBy`, don't use aggregate modes like `mq('count')` — they rewrite the SELECT and won't give you grouped results. Instead, write the aggregate yourself and use `mq('get')`:

```php
// ❌ Wrong — mq('count') with groupBy only returns first row
Order::groupBy('status')->mq('count')

// ✅ Correct — write the aggregate, use mq('get')
Order::selectRaw('status, COUNT(*) as total')
    ->groupBy('status')
    ->mq('get')
```

This applies to any query where you need grouped aggregates or custom aggregate expressions.

### Eager Loading (with) Not Supported

Eloquent's `with()` eager loading does **not** work with `mq()`. Eager loading fires separate queries behind the scenes after the main query — since `mq()` extracts raw SQL and sends it to the Go binary, Laravel never gets a chance to run those follow-up queries.

```php
// ❌ Won't load relations — with() is ignored
User::with('orders')->mq('get')

// ✅ Use join instead
User::select('users.*', 'orders.amount')
    ->join('orders', 'orders.user_id', '=', 'users.id')
    ->mq('get')

// ✅ Or run relations as separate parallel queries
MultiQuery::run([
    'users'  => User::mq('get'),
    'orders' => Order::whereIn('user_id', [1, 2, 3])->mq('get'),
]);
```

### Named Keys

```php
$results = MultiQuery::run([
    'users'   => User::where('active', 1)->mq('get'),
    'pending' => Order::where('status', 'pending')->mq('count'),
    'revenue' => Payment::mq('sum', 'amount'),
]);

$results['users']    // Collection of User
$results['pending']  // integer
$results['revenue']  // float
```

### Different DB Connection

```php
// all queries on reporting connection
MultiQuery::connection('reporting')->run([
    User::mq('get'),
    Order::mq('count'),
]);
```

### Manual Model Mapping (for DB::table queries)

```php
// DB::table has no model context — provide map
[$users] = MultiQuery::run(
    queries: [
        DB::table('users')->where('active', 1)->mq('get'),
    ],
    map: [
        0 => User::class,
    ]
);

// named key mapping
$results = MultiQuery::run(
    queries: [
        'users' => DB::table('users')->mq('get'),
    ],
    map: [
        'users' => User::class,
    ]
);
```

### Manual Convert

```php
// convert after run
[$rawUsers] = MultiQuery::run([
    DB::table('users')->mq('get'),
]);

$users = MultiQuery::convert($rawUsers, User::class);

// or collection macro
$users = collect($rawUsers)->fromMq(User::class);
```

### Mixed — Raw SQL + Builder

```php
[$stats, $users] = MultiQuery::run([
    "SELECT COUNT(*) as total FROM legacy_table",
    User::where('active', 1)->mq('get'),
]);
```

---

## Error Handling

```php
use Mojahed\Exceptions\MultiQueryException;

try {
    [$users, $orders] = MultiQuery::run([
        User::mq('get'),
        Order::mq('get'),
    ]);
} catch (MultiQueryException $e) {
    $e->getFailedIndex();   // which query failed (0-based)
    $e->getErrorString();   // MySQL error message
    $e->getResults();       // all results including successful ones
}
```

Disable throw in `config/multiquery.php`:
```php
'throw' => false,
// failed queries return null instead of throwing
```

---

## Real Dashboard Example

```php
$stats = MultiQuery::run([
    'total_users'     => User::mq('count'),
    'active_users'    => User::where('active', 1)->mq('count'),
    'total_orders'    => Order::mq('count'),
    'pending_orders'  => Order::where('status', 'pending')->mq('count'),
    'total_revenue'   => Payment::where('status', 'paid')->mq('sum', 'amount'),
    'today_revenue'   => Payment::whereDate('created_at', today())->mq('sum', 'amount'),
    'max_order'       => Order::mq('max', 'amount'),
    'min_order'       => Order::mq('min', 'amount'),
    'recent_orders'   => Order::latest()->take(10)->mq('get'),
    'top_products'    => DB::table('order_items')
                           ->select('product_id', DB::raw('SUM(qty) as sold'))
                           ->groupBy('product_id')
                           ->orderByDesc('sold')
                           ->take(5)
                           ->mq('get'),
]);

// all 8 queries fired simultaneously
// total time = slowest single query
// instead of sum of all queries
```

---

## Config Reference

```php
// config/multiquery.php
return [
    'binary'     => env('MULTIQUERY_BIN', '/usr/local/bin/msquery'),
    'connection' => env('DB_CONNECTION', 'mysql'),
    'timeout'    => env('MULTIQUERY_TIMEOUT', 30),
    'throw'      => true,
];
```
