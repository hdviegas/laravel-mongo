<?php

namespace Lindelius\LaravelMongo;

use DateTime;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use Lindelius\LaravelMongo\Events\WriteOperationFailed;
use MongoDB\BSON\ObjectID;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\WriteConcern;
use RuntimeException;

/**
 * Abstract model for MongoDB documents.
 *
 * @author  Tom Lindelius <tom.lindelius@gmail.com>
 * @package Lindelius\LaravelMongo
 * @version 0.2
 */
abstract class Model implements JsonSerializable
{
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
     * The maximum number of times to retry a write operation.
     *
     * @var int
     */
    protected static $maxRetryAttempts = 2;

    /**
     * Whether the object is currently stored in the database.
     *
     * @internal
     * @var bool
     */
    private $persisted = false;

    /**
     * The objects's properties.
     *
     * @internal
     * @var array
     */
    private $properties = [];

    /**
     * Whether to use soft deletes for objects using this model.
     *
     * @var bool
     */
    protected static $softDeletes = false;

    /**
     * Whether to automatically set timestamps on objects using this model.
     *
     * @var bool
     */
    protected static $timestamps = true;

    /**
     * The updates to send to the database when saving the object.
     *
     * @internal
     * @var array
     */
    private $updates = [];

    /**
     * Whether to force the server to wait for the journal to be commited before acknowledging an operation.
     *
     * @var bool
     */
    protected static $waitForJournal = false;

    /**
     * The write concern to use for the database operations.
     *
     * @var int
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
            'writeConcern'     => static::writeConcern()
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
     * Gets the object's updates as a bulk operation.
     *
     * @return array|null
     */
    public function asBulkOperation()
    {
        if (empty($this->updates) && $this->isPersisted()) {
            return null;
        }

        $id = $this->getId();

        if ($id === false) {
            return null;
        }

        $bulkOperation = null;

        if ($id === null) {
            if (static::$timestamps) {
                $this->updateProperty('updated_at', new DateTime());
                $this->updateProperty('created_at', new DateTime());
            }

            $this->updateProperty('_id', new ObjectID());

            $bulkOperation = [
                'insertOne' => [
                    convertDateTimeObjects($this->updates)
                ]
            ];
        } else {
            $updateOptions = !$this->isPersisted() ? ['upsert' => true] : [];

            if (static::$timestamps) {
                $this->updateProperty('updated_at', new DateTime());

                if (empty($this->properties['created_at'])) {
                    $this->updateProperty('created_at', new DateTime());
                }
            }

            $bulkOperation = [
                'updateOne' => [
                    ['_id' => $id],
                    ['$set' => convertDateTimeObjects($this->updates)],
                    $updateOptions
                ]
            ];
        }

        $this->persisted = true;
        $this->updates   = [];

        return $bulkOperation;
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

        static::$collection = static::database()->selectCollection(
            static::$collectionName,
            ['writeConcern' => static::writeConcern()]
        );

        return static::$collection;
    }

    /**
     * Gets the number of objects that matches the given filter.
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
     * Deletes all the objects that matches the given filter.
     *
     * @param  array $filter
     * @param  array $options
     * @return int|bool The number of deleted documents. False, if the operation was not acknowledged.
     * @throws Exception
     */
    public static function deleteMany(array $filter = [], array $options = [])
    {
        $result = static::collection()->deleteMany($filter, $options);

        if ($result->isAcknowledged()) {
            return $result->getDeletedCount();
        }

        return false;
    }

    /**
     * Deletes the first object that matches the given filter.
     *
     * @param  array $filter
     * @param  array $options
     * @return int|bool The number of deleted documents. False, if the operation was not acknowledged.
     * @throws Exception
     */
    public static function deleteOne(array $filter = [], array $options = [])
    {
        $result = static::collection()->deleteOne($filter, $options);

        if ($result->isAcknowledged()) {
            return $result->getDeletedCount();
        }

        return false;
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
     * Finds and returns all the objects that matches the given filter.
     *
     * @param  array $filter
     * @param  array $options
     * @return Model[]
     * @throws Exception
     */
    public static function find(array $filter = [], array $options = [])
    {
        $cursor = static::collection()->find($filter, $options);
        $models = [];

        foreach ($cursor as $document) {
            $models[] = static::newInstance($document);
        }

        return $models;
    }

    /**
     * Finds and returns the object that matches the given ID.
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
     * Finds and returns the object that matches the given filter.
     *
     * @param  array $filter
     * @param  array $options
     * @return Model|null
     * @throws Exception
     */
    public static function findOne(array $filter = [], array $options = [])
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
     * Completely deletes the object from the database.
     *
     * @internal
     * @return bool
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
                static::collection()->deleteOne(['_id' => $id]);

                if ($this->properties['_id'] instanceof ObjectID) {
                    unset($this->properties['_id']);
                }

                unset($this->properties['created_at']);
                unset($this->properties['deleted_at']);
                unset($this->properties['updated_at']);

                $this->persisted = false;
                $this->updates   = $this->properties;

                return true;
            } catch (Exception $e) {
                event(new WriteOperationFailed($e, $this));
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

        $this->updateProperty('_id', new ObjectID());

        $attempt = 1;

        do {
            try {
                static::collection()->insertOne(convertDateTimeObjects($this->updates));

                $this->persisted = true;
                $this->updates   = [];

                return true;
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '_id_ dup key') !== false) {
                    return true;
                }

                event(new WriteOperationFailed($e, $this));
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
                $result = static::collection()->updateOne(
                    ['_id' => $id],
                    ['$unset' => ['deleted_at' => '']]
                );

                unset($this->properties['deleted_at']);
                unset($this->updates['deleted_at']);

                if ($result->isAcknowledged() && $result->getMatchedCount() === 0) {
                    $this->persisted = false;

                    return false;
                }

                return true;
            } catch (Exception $e) {
                event(new WriteOperationFailed($e, $this));
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

                $result = static::collection()->updateOne(
                    ['_id' => $id],
                    ['$set' => ['deleted_at' => getBsonDateFromDateTime($now)]]
                );

                if ($result->isAcknowledged() && $result->getMatchedCount() === 0) {
                    $this->persisted = false;

                    return false;
                }

                $this->properties['deleted_at'] = $now;

                unset($this->updates['deleted_at']);

                return true;
            } catch (Exception $e) {
                event(new WriteOperationFailed($e, $this));
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

        $property = trim($property);

        if ($property[0] === '$') {
            throw new InvalidArgumentException('The property name must not start with a dollar sign.');
        }

        checkNestedFieldNames($newValue);

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
                $result = static::collection()->updateOne(
                    ['_id' => $id],
                    ['$set' => convertDateTimeObjects($this->updates)],
                    $this->isPersisted() ? [] : ['upsert' => true]
                );

                if ($result->isAcknowledged() && $result->getMatchedCount() === 0) {
                    $this->persisted = false;

                    return false;
                }

                $this->persisted = true;
                $this->updates   = [];

                return true;
            } catch (Exception $e) {
                event(new WriteOperationFailed($e, $this));
            }

            $attempt++;
        } while ($attempt <= static::$maxRetryAttempts);

        return false;
    }

    /**
     * Gets a new, pre-configured write concern object.
     *
     * @return WriteConcern
     * @throws InvalidArgumentException
     */
    protected static function writeConcern()
    {
        if (static::$waitForJournal) {
            return new WriteConcern(static::$writeConcern, 0, true);
        }

        return new WriteConcern(static::$writeConcern);
    }
}
