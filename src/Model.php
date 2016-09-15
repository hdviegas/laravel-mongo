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
     * @const OP_INSERT
     */
    const OP_INSERT = 0;

    /**
     * @const OP_UPSERT
     */
    const OP_UPSERT = 1;

    /**
     * @const OP_SOFT_DELETE
     */
    const OP_SOFT_DELETE = 2;

    /**
     * @const OP_HARD_DELETE
     */
    const OP_HARD_DELETE = 3;

    /**
     * @const OP_RESTORE
     */
    const OP_RESTORE = 4;

    /**
     * @var Collection|null
     */
    private $collection = null;

    /**
     * @var string|null
     */
    protected $collectionName = null;

    /**
     * @var Database|null
     */
    private $database = null;

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
            'collection'       => $this->collection,
            'collectionName'   => $this->collectionName,
            'database'         => $this->database,
            'maxRetryAttempts' => static::$maxRetryAttempts,
            'persisted'        => $this->persisted,
            'properties'       => $this->properties,
            'softDeletes'      => static::$softDeletes,
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
                    throw new RuntimeException('Nested field names must not contain any dots (.) or start with a dollar sign ($).');
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
    public function collection()
    {
        if ($this->collection instanceof Collection) {
            return $this->collection;
        }

        if (!is_string($this->collectionName) || $this->collectionName === '') {
            throw new Exception('The model "' . get_class($this) . '" is not associated with a database collection.');
        }

        $this->database = app('db')->getDatabase();

        if ($this->database instanceof Database) {
            $this->collection = $this->database->selectCollection($this->collectionName);

            return $this->collection;
        }

        throw new Exception('The database object for the "' . get_class($this) . '" model is not properly configured.');
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
     * Gets a new, pre-configured `MongoDB\Driver\WriteConcern` object.
     *
     * @return WriteConcern
     * @throws InvalidArgumentException
     */
    protected function getWriteConcern()
    {
        if (static::$waitForJournal) {
            return new WriteConcern(static::$writeConcern, 0, true);
        }

        return new WriteConcern(static::$writeConcern);
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
     */
    private function hardDelete()
    {
        $collection = $this->collection();

        if (!$collection instanceof Collection) {
            throw new Exception('The model "' . get_class($this) . '" is not associated with a database collection.');
        }

        $id = $this->getId();

        if ($id === null || $id === false) {
            return false;
        }

        if (!$this->isPersisted()) {
            return true;
        }

        $attempt = 1;

        do {
            try {
                $deleteResult = $collection->deleteOne(
                    ['_id' => $id],
                    ['writeConcern' => $this->getWriteConcern()]
                );

                if ($deleteResult->isAcknowledged()) {
                    $this->persisted = false;
                    $this->updates   = $this->properties;

                    unset($this->properties['_id']);
                    unset($this->properties['created_at']);
                    unset($this->properties['deleted_at']);
                    unset($this->properties['updated_at']);

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
     */
    private function insert()
    {
        $collection = $this->collection();

        if (!$collection instanceof Collection) {
            throw new Exception('The model "' . get_class($this) . '" is not associated with a database collection.');
        }

        if ($this->isPersisted()) {
            return empty($this->updates);
        }

        $attempt    = 1;
        $properties = array_merge(
            [
                'created_at' => new DateTime(),
                'deleted_at' => null,
                'updated_at' => new DateTime()
            ],
            $this->properties,
            ['_id' => new ObjectID()]
        );

        do {
            try {
                $insertResult = $collection->insertOne(
                    convertDateTimeObjects($properties),
                    ['writeConcern' => $this->getWriteConcern()]
                );

                if ($insertResult->isAcknowledged() && $insertResult->getInsertedCount() === 1) {
                    $this->persisted  = true;
                    $this->updates    = [];
                    $this->properties = $properties;

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
     * Restores the object if it has been previously soft deleted.
     *
     * @return bool
     * @throws Exception
     */
    public function restore()
    {
        $collection = $this->collection();

        if (!$collection instanceof Collection) {
            throw new Exception('The model "' . get_class($this) . '" is not associated with a database collection.');
        }

        $id = $this->getId();

        if ($id === null || $id === false || !$this->isPersisted()) {
            return false;
        }

        if (!$this->isDeleted()) {
            return true;
        }

        $attempt = 1;

        do {
            try {
                $updateResult = $collection->updateOne(
                    ['_id' => $id],
                    ['$set' => ['deleted_at' => null]],
                    ['writeConcern' => $this->getWriteConcern()]
                );

                if ($updateResult->isAcknowledged() && $updateResult->getMatchedCount() === 1) {
                    $this->properties['deleted_at'] = null;

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
     * Sets the database object this model should use for its database-backed operations.
     *
     * @param Database $database
     */
    public function setDatabase(Database $database)
    {
    	$this->collection = null;
        $this->database   = $database;
    }

    /**
     * Prepares a new update, and optimizes any related, previous updates.
     *
     * @internal
     * @param  string $field
     * @param  mixed  $value
     * @throws InvalidArgumentException
     */
    private function setUpdate($field, $value)
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
     * Soft deletes the object.
     *
     * @internal
     * @return bool
     * @throws Exception
     */
    private function softDelete()
    {
        $collection = $this->collection();

        if (!$collection instanceof Collection) {
            throw new Exception('The model "' . get_class($this) . '" is not associated with a database collection.');
        }

        $id = $this->getId();

        if ($id === null || $id === false || !$this->isPersisted()) {
            return false;
        }

        if ($this->isDeleted()) {
            return true;
        }

        $attempt = 1;
        $now     = new DateTime();

        do {
            try {
                $updateResult = $collection->updateOne(
                    ['_id' => $id],
                    ['$set' => ['deleted_at' => getBsonDateFromDateTime($now)]],
                    ['writeConcern' => $this->getWriteConcern()]
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
            throw new InvalidArgumentException('The property name must not start with a dollar sign ($).');
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
                $this->setUpdate($property, $newValue);
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
     */
    private function upsert()
    {
        $collection = $this->collection();

        if (!$collection instanceof Collection) {
            throw new Exception('The model "' . get_class($this) . '" is not associated with a database collection.');
        }

        $id = $this->getId();

        if ($id === false) {
            throw new RuntimeException('Tried to save an object (' . get_class($this) . ') with an invalid ID.');
        }

        if (empty($this->updates) && $this->isPersisted()) {
            return true;
        }

        $attempt = 1;
        $now     = new DateTime();

        do {
            try {
                $updateResult = $collection->updateOne(
                    ['_id' => $id],
                    [
                        '$set'         => convertDateTimeObjects(array_merge(['updated_at' => $now], $this->updates)),
                        '$setOnInsert' => [
                            'created_at' => getBsonDateFromDateTime($now),
                            'deleted_at' => null
                        ]
                    ],
                    [
                        'upsert'       => true,
                        'writeConcern' => $this->getWriteConcern()
                    ]
                );

                if ($updateResult->isAcknowledged() && ($updateResult->getUpsertedCount() + $updateResult->getMatchedCount() === 1)) {
                    $this->persisted  = true;
                    $this->updates    = [];
                    $this->properties = array_merge(
                        [
                            'created_at' => $now,
                            'deleted_at' => null
                        ],
                        $this->properties,
                        ['updated_at' => $now]
                    );

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
     * Gets a new model instance with a pre-configured database object.
     *
     * @param  Database $database
     * @return Model
     */
    public static function withConnection(Database $database)
    {
        $instance = new static();
        $instance->setDatabase($database);

        return $instance;
    }
}
