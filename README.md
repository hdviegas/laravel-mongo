# laravel-mongo
Convenience library for working with MongoDB documents in Laravel.

This library is built on top of [the official MongoDB PHP library](https://github.com/mongodb/mongo-php-library) and includes an abstract model, database wrappers for Laravel and Lumen, and some necessary helper functions.

Please note that this library is not intended to be a complete ORM and does not extend Eloquent.

## Installation

In order to install this library, issue the following command from your Laravel/Lumen project's root folder:

```
composer require "lindelius/laravel-mongo=^0.2"
```

After installing the library, configure your database connection by adding and editing the following array to the `config/database.php` file.

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

## Usage
### Database Connector
This convenience library includes two things, an abstract model and a database connector for Laravel and Lumen.
The database connector can be used by its own after configuring the connection (see the installation instructions) to resolve `MongoDB\Client`, `MongoDB\Database` and `MongoDB\Collection` instances out of the application container.
```php
/**
 * @var Lindelius\LaravelMongo\MongoDbConnection $connection
 */
$connection = app('db');

/**
 * @var MongoDB\Client     $client
 * @var MongoDB\Database   $database
 * @var MongoDB\Collection $collection
 */
$client     = $connection->getClient();
$database   = $connection->getDatabase();
$collection = $connection->getCollection('collection_name');
```
### Abstract Model
Like previously mentioned, this library also includes an abstract model that you may use as a base class for your MongoDB-backed object models.
```php
<?php

namespace App;

use Lindelius\LaravelMongo\Model;

class Jedi extends Model
{
    /**
     * Used for caching the collection object.
     *
     * We override this property since we most likely do not want to 
     * share the collection instance with other subclasses of Model.
     *
     * @var \MongoDB\Collection
     */
    protected static $collection = null;
    
    /**
     * The name of the database collection.
     *
     * @var string
     */
    protected static $collectionName = 'jedi';
    
    /**
     * Used for caching the database object.
     *
     * We override this property since might not want to share the
     * database instance with other subclasses of Model.
     *
     * @var \MongoDB\Database
     */
    protected static $database = null;
    
    /**
     * Constructor for Jedi objects.
     *
     * @param  string $name
     * @throws Exception
     */
    public function __construct($name)
    {
        $this->setName($name);
    }
    
    /**
     * Gets a new instance of the model.
     *
     * We override this method since we have added required parameters
     * to the Jedi constructor.
     *
     * @param  array $attributes
     * @return Jedi
     */
    public static function newInstance(array $attributes = [])
    {
        $instance = new static(@$attributes['name']);
        unset($attributes['name']);
        $instance->fill($attributes);
        
        return $instance;
    }
    
    /**
     * Sets the name of the Jedi.
     *
     * @param  string $name
     * @throws Exception
     */
    public function setName($name)
    {
        if (!is_string($name)) {
            throw new InvalidArgumentException('Invalid name.');
        }
    
        $this->updateProperty('name', $name);
    }
}

```
After having extended the `Model` class and added the necessary properties and methods we can now use all of the included magic in our brand new `Jedi` class.
```php
$jedi = new Jedi('Anakin Skywalker');

if ($jedi->save()) {
    echo 'Uh, I think we just made a horrible decision...';
} else {
    echo 'We failed to teach ' . $jedi->name . ' to use the Force.';
}
```
As you can see in the example above, the abstract `Model` class overrides the `__get()` magic method, allowing you to access the object's field values like they are regular public properties.
#### Instance methods
##### delete
The `delete()` method allows you to delete the object from the MongoDB database. When successfully called, the `_id` field along with the automatic timestamp fields (if used) will be unset. All other properties will still be available on the object, and they will also be prepared as "updates" in case you want to save the object again afterwards.

```php
$masterYoda = new Jedi('Yoda');

// Approximately 900 years later...

if ($masterYoda->delete()) {
    echo 'RIP ' . $masterYoda->name . ' :(';
}
```
If you have set `Model::$softDeletes` to `true` for this model, the field `deleted_at` will be set to the current time instead of the object being actually deleted.
If you wish to hard delete an object that is using soft deletes, you can do so by passing `true` as a parameter to the `delete()` method.
```php
$objectUsingSoftDeletes->delete(true);
```
##### isDeleted
This convenience method checks whether the object has been "soft" deleted. In order to use soft deletes you have to override the `Model::$softDeletes` property and set it to `true`.
##### isPersisted
This convenience method checks whether the object has been previously saved to the database.
##### restore
The `restore()` method restores the object if it has previously been "soft" deleted. Do note, though, that if you pass `true` to the `delete()` method it will completely delete the object from the database, meaning you can no longer restore it. Don't forget that you can save it again, though, as long as you don't clear or delete the PHP object.
```php
if ($objectUsingSoftDeletes->delete()) {
    echo 'The object has been soft deleted.';
    
    if ($objectUsingSoftDeletes->restore()) {
        echo 'The object has been restored.';
    }
}
```
##### save
The `save()` method saves the object to the database. Depending on the type of ID used for the model, and whether the object has already been persisted, the save method will call one of two internal methods, `insert()` or `upsert()`. You don't have to think about this yourself. Whether you just updated a single field, or if you just created the object and need to insert the whole thing, just call `save()` and let it do everything for you.
```php

$jedi = new Jedi('Anakin Skywalker');
$jedi->save(); // Saves the entire object

// Shit happens... Poor decisions are made...

$jedi->setName('Darth Vader');

$sith = $jedi;
unset($jedi);

$sith->save(); // Just saves the new value for the name field

```
#### Class methods
##### count
This method returns the object count for the model. If you would like to count just a subset of the objects, you can add a filter to the count query.
```php
$totalCount     = Jedi::count();
$skywalkerCount = Jedi::count(['name' => ['$regex' => 'Skywalker']]);
```
##### deleteMany
This method deletes all objects that matches a given filter and then returns the number of objects that were deleted. The default filter is just an empty array, so if you don't pass your own filter, this method will delete all objects that are using this model.
```php
$yodasDeleted = Jedi::deleteMany(['name' => 'Yoda']);
$jedisDeleted = Jedi::deleteMany();
```
##### deleteOne
This method deletes the first object that matches a given filter and then returns the number of objects that were deleted (either 1 or 0). Just like with the `Model::deleteMany()` method, the filter defaults to an empty array, so if you want to delete a specific object you have to specify a filter.
```php
if (Jedi::deleteOne() === 1) {
    echo 'A Jedi was deleted.';
}

if (Jedi::deleteOne(['name' => 'Mace Windu']) === 1) {
    echo 'Mace Windu was deleted.';
}
```
##### find
This method finds all objects that matches a given filter. If you don't specify a filter, all objects will be returned.
```php
/**
 * @var Jedi[] $allJedis
 * @var Jedi[] $skywalkers
 */
$allJedis   = Jedi::find();
$skywalkers = Jedi::find(['name' => ['$regex' => 'Skywalker']]);
```
##### findById
This method finds an object by its primary ID. Usually this is a `MongoDB\BSON\ObjectID` object.
```php
/**
 * @var Jedi|null $yoda
 */
$yoda = Jedi::findById($yodasId);

if ($yoda === null) {
    echo 'Unable to find Master Yoda.';
}
```
##### findOne
This method works just like `Model::findById()`, except that you can match on anything and not just the primary ID. If you want to find a specific object, make sure to specify a filter.
```php
/**
 * @var Jedi|null $yoda
 */
$yoda = Jedi::findOne(['name' => 'Yoda']);

if ($yoda === null) {
    echo 'Unable to find Master Yoda.';
}
```