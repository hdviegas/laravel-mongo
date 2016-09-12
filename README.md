# laravel-mongo
Convenience library for working with MongoDB documents in Laravel.

## Installation

Configure your database connection by adding and editing the following array to the `config/database.php` file.

```php
'mongodb' => [
    'driver'        => 'mongodb',
    'hosts'         => env('DB_HOST', 'localhost:27017'),
    'database'      => env('DB_DATABASE', 'test'),
    'username'      => env('DB_USERNAME', ''),
    'password'      => env('DB_PASSWORD', ''),
    'uriOptions'    => [
        'authSource' => env('DB_AUTHSOURCE', 'admin'),
        'replicaSet' => empty(env('DB_RSNAME')) ? null : env('DB_RSNAME', 'rs1'),
    ],
    'driverOptions' => [],
],
```

### Laravel
For Laravel installations, add the included service provider to the `config/app.php` file.

```php
Lindelius\LaravelMongo\MongoDbServiceProvider::class,
```

### Lumen
For Lumen installations, add the included service provider to the `bootstrap/app.php` file.

```php
$app->register('Lindelius\LaravelMongo\MongoDbServiceProvider');
```
