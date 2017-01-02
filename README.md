# laravel-mongo
Convenience library for working with MongoDB documents in Laravel and Lumen.

This library is built on top of [the official MongoDB PHP library](https://github.com/mongodb/mongo-php-library) and includes an abstract model, a helper class for handling bulk writes, database wrappers for Laravel and Lumen, and some necessary helper functions.

Please note that this library does *not* extend Eloquent, as Eloquent is designed and written for SQL databases.

## Table of Contents

* [Installation](#installation)
* [Usage](#usage)
    * [Database Connector](#database-connector)
    * [Bulk Builder](#bulk-builder)
    * [Abstract Model](#abstract-model)
        * [Instance Methods](#instance-methods)
            * [asBulkOperation](#asbulkoperation)
            * [delete](#delete)
            * [fill](#fill)
            * [getCreatedAt](#getcreatedat)
            * [getDeletedAt](#getdeletedat)
            * [getId](#getid)
            * [getUpdatedAt](#getupdatedat)
            * [isDeleted](#isdeleted)
            * [isPersisted](#ispersisted)
            * [restore](#restore)
            * [save](#save)
            * [toArray](#toarray)
        * [Class Methods](#class-methods)
            * [aggregate](#aggregate)
            * [count](#count)
            * [deleteMany](#deletemany)
            * [deleteOne](#deleteone)
            * [distinct](#distinct)
            * [find](#find)
            * [findById](#findbyid)
            * [findOne](#findone)
        * [Extending the Abstract Model](#extending-the-abstract-model)
            * [After Save](#after-save)
            * [Before Save](#before-save)
            * [Connection Settings](#connection-settings)
            * [Custom Document IDs](#custom-document-ids)
            * [Read Preference](#read-preference)
            * [Soft Deletes](#soft-deletes)
            * [Timestamps](#timestamps)
            * [Unset Null Value Fields](#unset-null-value-fields)
            * [Write Concern](#write-concern)

## Installation
In order to install this library, issue the following command from your Laravel or Lumen project's root folder:

```
composer require "lindelius/laravel-mongo=^0.3"
```

After installing the library, configure your database connection by adding the following to the _connections_ array in the `config/database.php` file (for Lumen installations you will first have to create the _config_ directory yourself and then copy the `database.php` file from `vendor/laravel/lumen-framework/config/`). You will also have to add the `DB_AUTHSOURCE` and `DB_RSNAME` variables to the environment file, if you'd like to override the default values.

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
For Laravel installations, add the included service provider to the _providers_ array in the `config/app.php` file.

```php
Lindelius\LaravelMongo\MongoDbServiceProvider::class,
```

### Lumen
For Lumen installations, add the included service provider to the `bootstrap/app.php` file.

```php
$app->register(Lindelius\LaravelMongo\MongoDbServiceProvider::class);
```

## Usage

### Database Connector
The database connector can be used by its own after configuring the connection (see the installation instructions). Via the connector you can resolve `MongoDB\Client`, `MongoDB\Database` and `MongoDB\Collection` instances out of the application container.

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

### Bulk Builder
The bulk builder is designed to make it incredibly easy to update multiple objects that extend the `Model` class. All you have to do is create a `BulkBuilder` instance and add the objects to it using the `BulkBuilder::add()` method. The bulk builder will automatically select the collection that is associated with the first object that is added, and then execute the write operations on that collection when the `BulkBuilder::execute()` method is called.

```php
$bulk  = new BulkBuilder();

foreach ($jedis as $jedi) {
    $bulk->add($jedi);
}

/**
 * @var MongoDB\BulkWriteResult $result
 */
$result = $bulk->execute();
```

The class also includes helper functions for adding custom write operations (i.e. operations that aren't taken directly from a `Model` object). These operations can be added to the bulk using the `BulkBuilder::deleteMany()`, `BulkBuilder::deleteOne()`, `BulkBuilder::insertOne()`, `BulkBuilder::replaceOne()`, `BulkBuilder::updateMany()`, and `BulkBuilder::updateOne()` methods. In this case you will have to manually inject the collection into the `BulkBuilder`, either via its constructor or via the `BulkBuilder::setCollection()` method.

```php
$bulk = new BulkBuilder($collection);

foreach ($jedis as $jedi) {
    $bulk->insertOne(['name' => $jedi->name]);
}

$bulk->execute();
```

### Abstract Model
Like previously mentioned, this library also includes an abstract model that you may use as a base class for your MongoDB-backed object models.

```php
use Lindelius\LaravelMongo\Model;

/**
 * Class Jedi
 *
 * @property string $name
 */
class Jedi extends Model
{
    /**
     * The name of the database collection.
     *
     * @var string
     */
    protected static $collectionName = 'jedi';
}

```

After having extended the `Model` class and added the necessary properties (`Model::$collectionName`) we can now manage our Jedis with ease.

```php
$jedi = new Jedi();
$jedi->name = 'Anakin Skywalker';

if ($jedi->save()) {
    echo 'Uh, I think we just made a horrible decision...';
} else {
    echo 'We failed to teach ' . $jedi->name . ' to use the Force.';
    echo 'Oh, well. It was probably for the better.';
}
```

As you can see in the example above the abstract `Model` class overrides the magic `__get()` and `__set()` methods, allowing you to assign and access the object's field values just like if they were regular public properties.

#### Instance Methods

##### asBulkOperation
This is a convenience method for getting any unsaved object updates in bulk operation format. This method returns `null` if there are no updates to be made.

##### delete
The `Model::delete()` method allows you to delete the object from the database. When successfully called, the `_id` field along with the automatic timestamp fields (if used) will be unset from the object. All other properties will still be available on the object, and they will also be prepared as "updates" in case you would want to save the object again.

```php
$masterYoda = new Jedi();
$masterYoda->name = 'Yoda';

// Approximately 900 years later...

if ($masterYoda->delete()) {
    echo 'RIP ' . $masterYoda->name;
}
```

If you have set `Model::$softDeletes` to `true` for this model, the field `deleted_at` will be set to the current time instead of the object being actually deleted from the database. If you use this option you can have entities deleted as far as the application knows, but still keep the data in the database in case you need to restore it later on.

If you wish to hard delete an object that is using soft deletes, you can do so by passing `true` as a parameter to the `delete()` method.

```php
if ($objectUsingSoftDeletes->delete(true)) {
    echo 'The object has now been deleted from the database.';
}
```

##### fill
This method is mostly for internal use but can be used from anywhere if you need to populate a model object with data from an attributes array.

```php
$jedi = new Jedi();
$jedi->fill(['name' => 'Luke Skywalker']);
```

##### getCreatedAt
This is a getter method for retrieving the "created at" date, which is either a `DateTime` object or `null`.

##### getDeletedAt
This is a getter method for retrieving the "deleted at" date, which is either a `DateTime` object or `null`.

##### getId
This is a getter method for retrieving the model object's primary ID value (the `_id` field).

##### getUpdatedAt
This is a getter method for retrieving the "updated at" date, which is either a `DateTime` object or `null`.

##### isDeleted
This convenience method checks whether the object has been "soft" deleted. In order to use soft deletes you have to override the `Model::$softDeletes` property and set it to `true`.

##### isPersisted
This convenience method checks whether the object has been previously saved to the database.

##### restore
The `Model::restore()` method restores the object if it has previously been "soft" deleted.

```php
if ($objectUsingSoftDeletes->delete()) {
    echo 'The object has been soft deleted.';
    
    if ($objectUsingSoftDeletes->restore()) {
        echo 'The object has been restored.';
    }
}
```

Do note, though, that if you pass `true` to the `Model::delete()` method it will completely delete the object from the database, which means that you can no longer restore it. Don't forget that you can save it again, though, as long as you don't clear or delete the PHP object.

```php
if ($objectUsingSoftDeletes->delete(true)) {
    echo 'The object has been hard deleted.';
    
    if ($objectUsingSoftDeletes->save()) {
        echo 'The object has been saved.';
    }
}
```

##### save
The `Model::save()` method saves the object to the database. Depending on the type of ID used for the model, and whether the object has already been persisted, the save method will call one of two internal methods, `Model::insert()` or `Model::upsert()`. You don't have to think about this yourself. Whether you just updated a single field, or if you just created the object from scratch and need to insert the whole thing, just call `save()` and let it do everything for you.

```php
$jedi = new Jedi();
$jedi->name = 'Anakin Skywalker';
$jedi->save(); // Saves the entire object

// Shit happens... Poor decisions are made...

$sith = $jedi;
unset($jedi);

$sith->name = 'Darth Vader';
$sith->save(); // Just saves the new value for the name field
```

##### toArray
This is a convenience method for accessing the internal properties array.

```php
$jedi = new Jedi();
$jedi->name = 'Rey';

var_dump($jedi->toArray());
/**
 * Outputs: 
 * array(1) { ["name"] => string(3) "Rey" }
 */
```

#### Class Methods

##### aggregate
This method executes an aggregation pipeline on the collection that is associated with the model. This method wraps the `Collection::aggregate()` method from the official MongoDB PHP library. You can find examples and other usage information in their [documentation](https://docs.mongodb.com/php-library/master/tutorial/crud/#complex-queries-with-aggregation).

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

##### distinct
This method finds all distinct values for a given field and filter in the collection that is associated with the model. This method wraps the `Collection::distinct()` method from the official MongoDB PHP library. You can find examples and other usage information in their [documentation](https://docs.mongodb.com/php-library/master/reference/method/MongoDBCollection-distinct/).

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

#### Extending the Abstract Model

##### After Save
The model comes with a method &mdash; `Model::afterSave()` &mdash; that is fired after every successful save operation. Override this method if you need to update normalized data, or whatever else you might need to do.

##### Before Save
The model comes with a method &mdash; `Model::beforeSave()` &mdash; that is fired before every save operation. Override this method if you need to do any pre-save validations, calculations, or whatever else you might need to do.

##### Connection Settings
As previously mentioned (in the usage example), the only part of the abstract model that you must override is the `Model::$collectionName` property. Just set it to the name of the associated MongoDB collection and you're good to go.

```php
protected static $collectionName = 'name_of_collection';
```

The model uses the included "mongodb" connection by default. If you only require one database for your application, you shouldn't have to change anything. Just make sure to register the included service provider and properly configure the MongoDB connection (see the [Installation chapter](#installation)). However, if your use case requires multiple databases, or if you just want to rename the connection for some reason, you will have to override the default settings inside the model, as well. Fortunately, this is extremely easy. All you have to do is override the name of the connection by changing the value of the static `Model::$connectionName` property.

##### Custom Document IDs
If your models require custom IDs, i.e. any value other than a `MongoDB\BSON\ObjectID` object, you will have to override the `Model::getId()` method. If the values that the ID depends on are not properly set you have to make sure that the method returns `false`, or else the object will be saved with either an incorrect value or a `MongoDB\BSON\ObjectID` object as its ID.

```php
public function getId()
{
    if (empty($this->returnProperty('_id.account')) || empty($this->returnProperty('_id.date'))) {
        return false;
    }

    return $this->returnProperty('_id');
}
```

##### Read Preference
The read preference settings for the model objects uses the MongoDB default, which currently is "primaries from any replica sets". You can, however, easily override this by changing the static `Model::$readFromSets` and `Model::$readPreference` properties, or by overriding the static `Model::readPreference()` method.

See the MongoDB documentation on [read preference](https://docs.mongodb.com/v3.2/core/read-preference/) for more information.

##### Soft Deletes
The model comes with an option to use "soft" deletes, which means that the documents aren't actually deleted from the database when they are deleted in the application. Instead of hard deleting the documents, a field (`deleted_at`) is set on the document. The status of the documents can be checked in the application using the method `Model::isDeleted()`.

Soft deletes are disabled by default, but you can turn it on by setting the static `Model::$softDeletes` property to `true`.

##### Timestamps
The model automatically adds timestamps to the MongoDB documents by default. The timestamps are added on creation (`created_at`), updates (`updated_at`), and soft deletes (`deleted_at`). The fields are populated with `MongoDB\BSON\UTCDateTime` objects, but are automatically converted to `DateTime` objects when the documents are loaded into PHP.

You can turn automatic timestamps off by setting the static `Model::$timestamps` property to `false`. You can also customize the names of the timestamp fields by overriding the static `Model::$timestampFields` property.

```php
class User extends Model
{
    protected static $timestampFields = [
        'create' => 'registered_at',
        'delete' => 'deactivated_at',
        'update' => 'updated_at'
    ];
}
```

##### Unset Null Value Fields
While not really useful for databases that are using the WiredTiger storage engine (due to its compression), the model includes an option to automatically unset null value fields rather than to store the field and it's value.

This functionality is disabled by default but you can turn it on by setting the static `Model::$unsetNulls` property to `true`.

##### Write Concern
The write concern settings for the model objects uses the MongoDB default, which currently is "wait for 1 server to acknowledge, but don't wait for journal". You can, however, easily override this by changing the static `Model::$waitForJournal` and `Model::$writeConcern` properties, or by overriding the static `Model::writeConcern()` method.

See the MongoDB documentation on [write concern](https://docs.mongodb.com/v3.2/reference/write-concern/) for more information.
