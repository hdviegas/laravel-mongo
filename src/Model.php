<?php

namespace Lindelius\LaravelMongo;

use DateTime;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use MongoDB\BSON\ObjectID;
use RuntimeException;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\WriteConcern;
use MongoDB\Driver\Exception\Exception as MongoException;

/**
 * Abstract model for MongoDB documents.
 *
 * @author  Tom Lindelius <tom.lindelius@gmail.com>
 * @package Lindelius\LaravelMongo
 * @version 0.1
 */
abstract class Model implements JsonSerializable
{
    /**
     * @var int OP_INSERT
     */
    const OP_INSERT = 0;
    /**
     * @var int OP_UPSERT
     */
    const OP_UPSERT = 1;
    /**
     * @var int OP_SOFT_DELETE
     */
    const OP_SOFT_DELETE = 2;
    /**
     * @var int OP_HARD_DELETE
     */
    const OP_HARD_DELETE = 3;
    /**
     * @var int OP_RESTORE
     */
    const OP_RESTORE = 4;

    /**
     * @var Collection|null
     */
    protected static $collection = null;

    /**
     * @var string|null
     */
    protected static $collectionName = null;

    /**
     * @var Database|null
     */
    protected static $database = null;

    /**
     * @var int The number of times to retry a write operation (`2` is the recommended value)
     */
    protected static $maxRetryAttempts = 2;

    /**
     * @internal
     * @var bool Whether the object is currently stored in the database
     */
    private $persisted = false;

    /**
     * @internal
     * @var array The objects's properties
     */
    private $properties = [];

    /**
     * @var bool Whether to use soft deletes
     */
    protected static $softDeletes = false;

    /**
     * @var bool Whether to automatically set timestamps
     */
    protected static $timestamps = true;

    /**
     * @internal
     * @var array The updates to make when saving the object
     */
    private $updates = [];

    /**
     * @var bool Whether to force the server to wait for the journal to be commited before acknowledging an operation
     */
    protected static $waitForJournal = false;

    /**
     * @var int The write concern to use for the MongoDB operations
     */
    protected static $writeConcern = 1;

    /**
     * Gets the data that should be output when the object is passed to `var_dump()`.
     *
     * @return array
     * @see    http://php.net/manual/en/language.oop5.magic.php#object.debuginfo
     */
    public function __debugInfo()
    {
        return [
            'collection'       => static::$collection,
            'collectionName'   => static::$collectionName,
            'database'         => static::$database,
            'maxRetryAttempts' => static::$maxRetryAttempts,
            'persisted'        => $this->persisted,
            'properties'       => $this->properties,
            'softDeletes'      => static::$softDeletes,
            'timestamps'       => static::$timestamps,
            'updates'          => $this->updates,
            'waitForJournal'   => static::$waitForJournal,
            'writeConcern'     => static::$writeConcern
        ];
    }

    /**
     * Gets the current value for a given property.
     *
     * @param  string $property
     * @return mixed
     * @see    http://php.net/manual/en/language.oop5.overloading.php#object.get
     */
    public function __get($property)
    {
        return $this->returnProperty($property);
    }

    /**
     * Checks nested field names, making sure they only contain valid characters.
     *
     * @internal
     * @param  mixed $object
     * @param  int   $nestingLevel
     * @throws RuntimeException
     */
    private function checkNestedFieldNames($object, $nestingLevel = 0)
    {
        $nestingLevel++;

        if ($nestingLevel > 10) {
            throw new RuntimeException('The object is nested too deep.');
        }

        if (is_array($object) || is_object($object)) {
            foreach ($object as $key => $value) {
                if (is_string($key) && (strpos($key, '.') !== false || trim($key)[0] === '$')) {
                    throw new RuntimeException('Nested field names must not contain any dots, or start with a dollar sign.');
                }

                if (is_array($value) || is_object($value)) {
                    $this->checkNestedFieldNames($value, $nestingLevel);
                }
            }
        }
    }

    /**
     * Gets the collection object associated with this model.
     *
     * @return Collection
     * @throws Exception
     */
    public static function collection()
    {
        if (static::$collection instanceof Collection) {
            return static::$collection;
        }

        if (!is_string(static::$collectionName)) {
            throw new Exception('The model "' . get_called_class() . '" is not associated with a database collection.');
        }

        static::$collection = static::database()->selectCollection(static::$collectionName);

        return static::$collection;
    }

    /**
     * Gets the number of model objects that matches the given filter.
     *
     * @param  array $filter
     * @param  array $options
     * @return int
     * @throws Exception
     */
    public static function count(array $filter = [], array $options = [])
    {
        return static::collection()->count($filter, $options);
    }

    /**
     * Gets the database object associated with this model.
     *
     * @param  Database|null $newDatabase
     * @return Database
     * @throws Exception
     */
    public static function database(Database $newDatabase = null)
    {
        if ($newDatabase instanceof Database) {
            static::$database = $newDatabase;

            return static::$database;
        }

        if (static::$database instanceof Database) {
            return static::$database;
        }

        $database = app('db')->getDatabase();

        if ($database instanceof Database) {
            static::$database = $database;

            return $database;
        }

        throw new Exception('The database object for the "' . get_called_class() . '" model is not properly configured.');
    }

    /**
     * Deletes the object.
     *
     * @param  bool $hardDelete
     * @return bool
     * @throws Exception
     */
    public function delete($hardDelete = false)
    {
        if (static::$softDeletes && !$hardDelete) {
            return $this->softDelete();
        }

        return $this->hardDelete();
    }

    /**
     * Populates the object's properties from an array of attributes.
     *
     * @param  array $attributes
     * @throws Exception
     */
    public function fill(array $attributes)
    {
        foreach ($attributes as $property => $value) {
            $this->updateProperty($property, $value, true);
        }
    }

    /**
     * Finds and returns all the model objects that matches the given filter.
     *
     * @param  array $filter
     * @param  array $options
     * @return Model[]
     * @throws Exception
     */
    public static function find(array $filter, array $options = [])
    {
        $cursor = static::collection()->find($filter, $options);
        $models = [];

        foreach ($cursor as $document) {
            $models[] = static::newInstance($document);
        }

        return $models;
    }

    /**
     * Finds and returns the model object that matches the given ID.
     *
     * @param  mixed $id
     * @return Model|null
     * @throws Exception
     */
    public static function findById($id)
    {
        return static::findOne(['_id' => $id]);
    }

    /**
     * Finds and returns the model object that matches the given filter.
     *
     * @param  array $filter
     * @param  array $options
     * @return Model|null
     * @throws Exception
     */
    public static function findOne(array $filter, array $options = [])
    {
        $document = static::collection()->findOne($filter, $options);
        $model    = null;

        if (!empty($document)) {
            $model = static::newInstance($document);
        }

        return $model;
    }

    /**
     * Gets the date and time when the object was first created.
     *
     * @return DateTime|null
     */
    public function getCreatedAt()
    {
        return $this->returnProperty('created_at');
    }

    /**
     * Gets the date and time when the object was soft deleted.
     *
     * @return DateTime|null
     */
    public function getDeletedAt()
    {
        return $this->returnProperty('deleted_at');
    }

    /**
     * Gets the object's primary ID.
     *
     * Override this method in order to define a custom value for the `_id` field. Make sure to return `false` if the
     * custom value is not properly set, or the object may be assigned an `ObjectID` instead.
     *
     * @return mixed
     */
    public function getId()
    {
        return $this->returnProperty('_id');
    }

    /**
     * Gets the date and time when the object was last updated.
     *
     * @return DateTime|null
     */
    public function getUpdatedAt()
    {
        return $this->returnProperty('updated_at');
    }

    /**
     * Handle failed write operations.
     *
     * If you would like to log failed write attempts, this is the place to do it.
     * If you do not want to retry any write operations, you can override this function to stop the execution flow by
     * rethrowing the exception.
     *
     * @param Exception $e
     * @param int       $operation
     */
    protected function handleFailedWrite(Exception $e, $operation)
    {
    }

    /**
     * Completely deletes the object from the database.
     *
     * @internal
     * @return bool
     * @throws Exception
     * @throws \MongoDB\Driver\Exception\Exception
     */
    private function hardDelete()
    {
        $id = $this->getId();

        if (!$this->isPersisted()) {
            return true;
        }

        $attempt = 1;

        do {
            try {
                $deleteResult = static::collection()->deleteOne(
                    ['_id' => $id],
                    ['writeConcern' => $this->writeConcern()]
                );

                if ($deleteResult->isAcknowledged()) {
                    unset($this->properties['_id']);
                    unset($this->properties['created_at']);
                    unset($this->properties['deleted_at']);
                    unset($this->properties['updated_at']);

                    $this->persisted = false;
                    $this->updates   = $this->properties;

                    return true;
                } else {
                    break;
                }
            } catch (Exception $e) {
                if ($e instanceof MongoException) {
                    $this->handleFailedWrite($e, self::OP_HARD_DELETE);

                    if ($attempt === static::$maxRetryAttempts) {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }

            $attempt++;
        } while ($attempt <= static::$maxRetryAttempts);

        return false;
    }

    /**
     * Inserts the object into the database.
     *
     * @internal
     * @return bool
     * @throws Exception
     * @throws \MongoDB\Driver\Exception\Exception
     */
    private function insert()
    {
        if ($this->isPersisted()) {
            return empty($this->updates);
        }

        if (static::$timestamps) {
            $this->updateProperty('updated_at', new DateTime());
            $this->updateProperty('created_at', new DateTime());
        }

        $this->properties['_id'] = new ObjectID();

        $attempt = 1;

        do {
            try {
                $insertResult = static::collection()->insertOne(
                    convertDateTimeObjects($this->properties),
                    ['writeConcern' => $this->writeConcern()]
                );

                if ($insertResult->isAcknowledged() && $insertResult->getInsertedCount() === 1) {
                    $this->persisted = true;
                    $this->updates   = [];

                    return true;
                } else {
                    break;
                }
            } catch (Exception $e) {
                if ($e instanceof MongoException) {
                    $this->handleFailedWrite($e, self::OP_INSERT);

                    if (strpos($e->getMessage(), '_id_ dup key') !== false) {
                        return true;
                    } elseif ($attempt === static::$maxRetryAttempts) {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }

            $attempt++;
        } while ($attempt <= static::$maxRetryAttempts);

        return false;
    }

    /**
     * Checks whether the object is deleted.
     *
     * @return bool
     */
    public function isDeleted()
    {
        return (!$this->isPersisted() || $this->getDeletedAt() !== null);
    }

    /**
     * Checks whether the object has been saved to the database.
     *
     * @return bool
     */
    public function isPersisted()
    {
        return $this->persisted;
    }

    /**
     * Serializes the object to a value that can be serialized natively by `json_encode()`.
     *
     * @return mixed
     * @see    http://php.net/manual/en/jsonserializable.jsonserialize.php
     */
    public function jsonSerialize()
    {
        return $this->properties;
    }

    /**
     * Gets a new instance of the model.
     *
     * Override this method if you add required parameters to the model's constructor, or if you have to pre-process
     * the attributes before instantiating the model instance.
     *
     * @param  array $attributes
     * @return Model
     */
    public static function newInstance(array $attributes = [])
    {
        $instance = new static();
        $instance->fill($attributes);

        return $instance;
    }

    /**
     * Prepares a new update, and optimizes any related, previous updates.
     *
     * @internal
     * @param  string $field
     * @param  mixed  $value
     * @throws InvalidArgumentException
     */
    private function prepareUpdate($field, $value)
    {
        if (!is_string($field)) {
            throw new InvalidArgumentException('The field name must be a valid string.');
        }

        $fieldPath = explode('.', $field);
        array_pop($fieldPath);

        $finalField    = $field;
        $finalValue    = $value;
        $updatedFields = array_keys($this->updates);

        if (!empty($fieldPath)) {
            $parentField      = null;
            $parentProperties = $this->properties;

            foreach ($fieldPath as $i => $part) {
                $checkpoint       = count($parentProperties) > 1;
                $parentProperties = $parentProperties[$part];

                if ($parentField === null) {
                    $parentField = $part;
                } else {
                    $parentField .= '.' . $part;
                }

                if (in_array($parentField, $updatedFields)) {
                    $finalField = $parentField;
                    $finalValue = $parentProperties;
                    break;
                } elseif ($i === 0 || $checkpoint) {
                    $finalField = $parentField;
                    $finalValue = $parentProperties;
                }
            }

            unset($parentProperties);
        }

        foreach ($this->updates as $updateField => $updateValue) {
            if (strpos($updateField, $finalField) === 0) {
                unset($this->updates[$updateField]);
            }
        }

        $this->updates[$finalField] = $finalValue;
    }

    /**
     * Restores the object if it has been previously soft deleted.
     *
     * @return bool
     * @throws Exception
     * @throws \MongoDB\Driver\Exception\Exception
     */
    public function restore()
    {
        $id = $this->getId();

        if (!$this->isPersisted()) {
            return false;
        }

        if (!$this->isDeleted()) {
            return true;
        }

        $attempt = 1;

        do {
            try {
                $updateResult = static::collection()->updateOne(
                    ['_id' => $id],
                    ['$unset' => ['deleted_at' => '']],
                    ['writeConcern' => $this->writeConcern()]
                );

                if ($updateResult->isAcknowledged() && $updateResult->getMatchedCount() === 1) {
                    unset($this->properties['deleted_at']);
                    unset($this->updates['deleted_at']);

                    return true;
                } else {
                    break;
                }
            } catch (Exception $e) {
                if ($e instanceof MongoException) {
                    $this->handleFailedWrite($e, self::OP_RESTORE);

                    if ($attempt === static::$maxRetryAttempts) {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }

            $attempt++;
        } while ($attempt <= static::$maxRetryAttempts);

        return false;
    }

    /**
     * Gets the current value for a given property.
     *
     * @param  string $property
     * @return mixed
     */
    protected function returnProperty($property)
    {
        if (!is_string($property)) {
            return null;
        }

        if (strpos($property, '.') === false) {
            return isset($this->properties[$property]) ? $this->properties[$property] : null;
        }

        $data   = $this->properties;
        $fields = explode('.', $property);

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                return null;
            }

            $data = $data[$field];
        }

        return $data;
    }

    /**
     * Saves the object to the database.
     *
     * @return bool
     * @throws Exception
     */
    public function save()
    {
        if ($this->getId() === null) {
            return $this->insert();
        }

        return $this->upsert();
    }

    /**
     * Soft deletes the object.
     *
     * @internal
     * @return bool
     * @throws Exception
     * @throws \MongoDB\Driver\Exception\Exception
     */
    private function softDelete()
    {
        $id = $this->getId();

        if (!$this->isPersisted()) {
            return false;
        }

        if ($this->isDeleted()) {
            return true;
        }

        $attempt = 1;

        do {
            try {
                $now = new DateTime();

                $updateResult = static::collection()->updateOne(
                    ['_id' => $id],
                    ['$set' => ['deleted_at' => getBsonDateFromDateTime($now)]],
                    ['writeConcern' => $this->writeConcern()]
                );

                if ($updateResult->isAcknowledged() && $updateResult->getMatchedCount() === 1) {
                    $this->properties['deleted_at'] = $now;

                    unset($this->updates['deleted_at']);

                    return true;
                } else {
                    break;
                }
            } catch (Exception $e) {
                if ($e instanceof MongoException) {
                    $this->handleFailedWrite($e, self::OP_SOFT_DELETE);

                    if ($attempt === static::$maxRetryAttempts) {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }

            $attempt++;
        } while ($attempt <= static::$maxRetryAttempts);

        return false;
    }

    /**
     * Gets the object as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->properties;
    }

    /**
     * Updates a given property with a new value, and returns the old value.
     *
     * @param  string $property
     * @param  mixed  $newValue
     * @param  bool   $isFilling
     * @return mixed
     * @throws Exception
     */
    protected function updateProperty($property, $newValue, $isFilling = false)
    {
        if (!is_string($property)) {
            throw new InvalidArgumentException('The property name must be a valid string.');
        }

        if (preg_match('/[^0-9\.\s]/', $property) !== 1) {
            throw new InvalidArgumentException('The property name must not be all numerical.');
        }

        $property = trim($property);

        if ($property[0] === '$') {
            throw new InvalidArgumentException('The property name must not start with a dollar sign.');
        }

        $this->checkNestedFieldNames($newValue);

        $newProperty  = &$this->properties;
        $oldValue     = null;
        $segments     = explode('.', $property);
        $firstSegment = $segments[0];

        $propertyBackup = isset($this->properties[$firstSegment]) ? $this->properties[$firstSegment] : null;

        try {
            foreach ($segments as $segment) {
                if (trim($segment) === '') {
                    throw new InvalidArgumentException('The property name is invalid.');
                }

                if (isset($newProperty) && !is_array($newProperty)) {
                    $newProperty = [];
                }

                $newProperty = &$newProperty[$segment];
            }

            if (isset($newProperty)) {
                $oldValue = $newProperty;
            }

            $newProperty = $isFilling ? convertBsonDateObjects($newValue) : $newValue;

            if ($isFilling) {
                $this->updates = [];
            } else {
                $this->prepareUpdate($property, $newValue);
            }
        } catch (Exception $e) {
            $this->properties[$firstSegment] = $propertyBackup;
            throw $e;
        }

        if ($property === '_id' && $isFilling) {
            $this->persisted = true;
        }

        return $oldValue;
    }

    /**
     * Upserts the object into the database.
     *
     * @internal
     * @return bool
     * @throws Exception
     * @throws \MongoDB\Driver\Exception\Exception
     */
    private function upsert()
    {
        $id = $this->getId();

        if ($id === false) {
            throw new RuntimeException('Tried to save an object (' . get_class($this) . ') with an invalid ID.');
        }

        if (empty($this->updates) && $this->isPersisted()) {
            return true;
        }

        if (static::$timestamps) {
            $this->updateProperty('updated_at', new DateTime());

            if (empty($this->properties['created_at'])) {
                $this->updateProperty('created_at', new DateTime());
            }
        }

        $attempt = 1;

        do {
            try {
                $updateResult = static::collection()->updateOne(
                    ['_id' => $id],
                    ['$set' => convertDateTimeObjects($this->updates)],
                    [
                        'upsert'       => true,
                        'writeConcern' => $this->writeConcern()
                    ]
                );

                if ($updateResult->isAcknowledged() && ($updateResult->getUpsertedCount() + $updateResult->getMatchedCount() === 1)) {
                    $this->persisted = true;
                    $this->updates   = [];

                    return true;
                } else {
                    break;
                }
            } catch (Exception $e) {
                if ($e instanceof MongoException) {
                    $this->handleFailedWrite($e, self::OP_UPSERT);

                    if ($attempt === static::$maxRetryAttempts) {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }

            $attempt++;
        } while ($attempt <= static::$maxRetryAttempts);

        return false;
    }

    /**
     * Gets a new, pre-configured `MongoDB\Driver\WriteConcern` object.
     *
     * @return WriteConcern
     * @throws InvalidArgumentException
     */
    protected function writeConcern()
    {
        if (static::$waitForJournal) {
            return new WriteConcern(static::$writeConcern, 0, true);
        }

        return new WriteConcern(static::$writeConcern);
    }
}
