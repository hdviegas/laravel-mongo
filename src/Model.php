<?php

namespace Lindelius\LaravelMongo;

use DateTime;
use Exception;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonSerializable;
use Lindelius\LaravelMongo\Events\WriteOperationFailed;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use RuntimeException;
use Traversable;

/**
 * Abstract model for MongoDB documents.
 *
 * @author  Tom Lindelius <tom.lindelius@gmail.com>
 * @package Lindelius\LaravelMongo
 * @version 0.3
 */
abstract class Model implements Jsonable, JsonSerializable
{
    /**
     * The name of the collection associated with this model.
     *
     * @var string|null
     */
    protected static $collectionName = null;

    /**
     * The name of the connection.
     *
     * @var string
     */
    protected static $connectionName = 'mongodb';

    /**
     * The connection resolver instance.
     *
     * @var ConnectionResolverInterface|null
     */
    protected static $connectionResolver = null;

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
     * The tagged server sets to read from. An empty array means all.
     *
     * @var array
     * @see https://docs.mongodb.com/manual/core/read-preference/#tag-sets
     */
    protected static $readFromSets = [];

    /**
     * The read preference to use for the database read operations.
     *
     * @var int
     * @see http://php.net/manual/en/mongodb-driver-readpreference.construct.php
     */
    protected static $readPreference = ReadPreference::RP_PRIMARY;

    /**
     * The object properties that were recently updated.
     *
     * @var array
     */
    private $recentlyUpdated = [];

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
     * The names of the automatic timestamp fields.
     *
     * @var array
     */
    protected static $timestampFields = [
        'create' => 'created_at',
        'delete' => 'deleted_at',
        'update' => 'updated_at',
    ];

    /**
     * Whether to unset null value fields in the database.
     *
     * @var bool
     */
    protected static $unsetNulls = false;

    /**
     * The updates to send to the database when saving the object.
     *
     * @internal
     * @var array
     */
    private $updates = [];

    /**
     * Custom validation messages for the model's properties.
     *
     * @var array
     */
    protected static $validationMessages = [];

    /**
     * Validation rules for the model's properties.
     *
     * @var array
     */
    protected static $validationRules = [];

    /**
     * Whether to force the server to wait for the journal to be commited
     * before acknowledging an operation.
     *
     * @var bool
     * @see https://docs.mongodb.com/manual/reference/write-concern/#j-option
     */
    protected static $waitForJournal = false;

    /**
     * The write concern to use for the database write operations.
     *
     * @var int
     * @see http://php.net/manual/en/mongodb-driver-writeconcern.construct.php
     */
    protected static $writeConcern = 1;

    /**
     * Gets the data that should be output when the object is passed to
     * `var_dump()`.
     *
     * @return array
     * @see    http://php.net/manual/en/language.oop5.magic.php#object.debuginfo
     */
    public function __debugInfo()
    {
        return [
            'collectionName'   => static::$collectionName,
            'connectionName'   => static::$connectionName,
            'maxRetryAttempts' => static::$maxRetryAttempts,
            'persisted'        => $this->persisted,
            'properties'       => $this->properties,
            'readPreference'   => static::readPreference(),
            'softDeletes'      => static::$softDeletes,
            'timestamps'       => static::$timestamps,
            'updates'          => $this->updates,
            'writeConcern'     => static::writeConcern(),
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
     * Sets a new value for a given property.
     *
     * @param  string $property
     * @param  mixed  $newValue
     * @see    http://php.net/manual/en/language.oop5.overloading.php#object.set
     */
    public function __set($property, $newValue)
    {
        $this->updateProperty($property, $newValue);
    }

    /**
     * Convert the model to its string representation.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * Executes an aggregation framework pipeline on the model's collection.
     *
     * @param  array $pipeline
     * @param  array $options
     * @return Traversable
     * @throws Exception
     * @see    Collection::aggregate()
     */
    public static function aggregate(array $pipeline = [], array $options = [])
    {
        return static::collection()->aggregate($pipeline, $options);
    }

    /**
     * Handles model related actions that should be performed after the object
     * has been successfully saved to the database.
     *
     * @param array $updatedFields
     */
    protected function afterSave(array $updatedFields = [])
    {
    }

    /**
     * Gets the object's updates as a bulk operation.
     * This method does not trigger the `Model::beforeSave()` or
     * `Model::afterSave()` methods.
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

        if (static::$timestamps) {
            $this->updateProperty(static::$timestampFields['update'], new DateTime());

            if (!$this->isPersisted()) {
                $this->updateProperty(static::$timestampFields['create'], new DateTime());
            }
        }

        if ($id === null) {
            $this->updateProperty('_id', new ObjectId());

            $bulkOperation = [
                'insertOne' => [
                    convertDateTimeObjects($this->updates),
                ],
            ];
        } else {
            $bulkOperation = [
                'updateOne' => [
                    ['_id' => $id],
                    ['$set' => convertDateTimeObjects($this->updates)],
                    $this->isPersisted() ? [] : ['upsert' => true],
                ],
            ];
        }

        $this->persisted = true;
        $this->updates   = [];

        return $bulkOperation;
    }

    /**
     * Handles model related actions that should be performed before the object
     * is saved to the database.
     *
     * @throws ValidationException
     */
    protected function beforeSave()
    {
        $this->validate();
    }

    /**
     * Clears all properties and prepared updates for the object.
     */
    protected function clearObject()
    {
        $this->persisted  = false;
        $this->properties = [];
        $this->updates    = [];
    }

    /**
     * Gets the collection object associated with this model.
     *
     * @return Collection
     * @throws Exception
     */
    public static function collection()
    {
        if (empty(static::$collectionName)) {
            throw new Exception(sprintf(
                'The model "%s" has not been assigned a collection.',
                get_called_class()
            ));
        }

        return static::database()->selectCollection(
            static::$collectionName,
            [
                'readPreference' => static::readPreference(),
                'writeConcern'   => static::writeConcern(),
            ]
        );
    }

    /**
     * Gets the connection used for this model.
     *
     * @return MongoDbConnection
     * @throws Exception
     */
    public static function connection()
    {
        if (empty(static::$connectionResolver)) {
            throw new Exception(sprintf(
                'The model "%s" is not using a connection resolver.',
                get_called_class()
            ));
        }

        if (empty(static::$connectionName)) {
            throw new Exception(sprintf(
                'The model "%s" has not been assigned a connection.',
                get_called_class()
            ));
        }

        $connection = static::$connectionResolver->connection(static::$connectionName);

        if (!$connection instanceof MongoDbConnection) {
            throw new RuntimeException(sprintf(
                'Unable to resolve a MongoDB connection for the model "%s".',
                get_called_class()
            ));
        }

        return $connection;
    }

    /**
     * Gets the number of objects that matches the given filter.
     *
     * @param  array $filter
     * @param  array $options
     * @return int
     * @throws Exception
     * @see    Collection::count()
     */
    public static function count(array $filter = [], array $options = [])
    {
        return static::collection()->count($filter, $options);
    }

    /**
     * Gets the database object associated with this model.
     *
     * @return Database
     * @throws Exception
     */
    public static function database()
    {
        $database = static::connection()->getDatabase();

        if (!$database instanceof Database) {
            throw new Exception(sprintf(
                'The database object for the "%s" model is not properly configured.',
                get_called_class()
            ));
        }

        return $database;
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
     * @return int|bool The number of deleted documents. False, if the
     *                  operation was not acknowledged.
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
     * @return int|bool The number of deleted documents. False, if the
     *                  operation was not acknowledged.
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
     * Finds the distinct values for a specified field across the model's
     * collection.
     *
     * @param  string $field
     * @param  array  $filter
     * @param  array  $options
     * @return mixed
     * @throws Exception
     * @see    Collection::distinct()
     */
    public static function distinct($field, array $filter = [], array $options = [])
    {
        return static::collection()->distinct($field, $filter, $options);
    }

    /**
     * Populates the object's properties from an array of attributes.
     *
     * @param  array $attributes
     * @throws Exception
     */
    public function fill(array $attributes)
    {
        $this->clearObject();

        foreach ($attributes as $property => $value) {
            $this->updateProperty($property, $value, true);
        }
    }

    /**
     * Finds and returns all the objects that matches the given filter.
     *
     * @param  array $filter
     * @param  array $options
     * @return static[]
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
     * @return static|null
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
     * @return static|null
     * @throws Exception
     */
    public static function findOne(array $filter = [], array $options = [])
    {
        $document = static::collection()->findOne($filter, $options);

        if (empty($document)) {
            return null;
        }

        return static::newInstance($document);
    }

    /**
     * Gets the date and time when the object was first created.
     *
     * @return DateTime|null
     */
    public function getCreatedAt()
    {
        return $this->returnProperty(static::$timestampFields['create']);
    }

    /**
     * Gets the date and time when the object was soft deleted.
     *
     * @return DateTime|null
     */
    public function getDeletedAt()
    {
        return $this->returnProperty(static::$timestampFields['delete']);
    }

    /**
     * Gets the object's primary ID.
     * Override this method in order to define a custom value for the `_id`
     * field. Make sure to return `false` if the custom value is not properly
     * set, or the object may be assigned a `MongoDB\BSON\ObjectId` instead.
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
        return $this->returnProperty(static::$timestampFields['update']);
    }

    /**
     * Completely deletes the object from the database.
     *
     * @internal
     * @return bool
     */
    private function hardDelete()
    {
        if (!$this->isPersisted()) {
            return true;
        }

        $attempt = 1;
        $id      = $this->getId();

        do {
            try {
                static::collection()->deleteOne(['_id' => $id]);

                if ($this->properties['_id'] instanceof ObjectId) {
                    unset($this->properties['_id']);
                }

                unset($this->properties[static::$timestampFields['create']]);
                unset($this->properties[static::$timestampFields['delete']]);
                unset($this->properties[static::$timestampFields['update']]);

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
            $this->updateProperty(static::$timestampFields['update'], new DateTime());
            $this->updateProperty(static::$timestampFields['create'], new DateTime());
        }

        $this->updateProperty('_id', new ObjectId());

        $valuesToSet = convertDateTimeObjects($this->updates);

        if (static::$unsetNulls) {
            foreach ($valuesToSet as $field => $value) {
                if ($value === null) {
                    unset($valuesToSet[$field]);
                }
            }
        }

        $attempt = 1;

        do {
            try {
                static::collection()->insertOne($valuesToSet);

                $this->persisted       = true;
                $this->recentlyUpdated = array_keys($valuesToSet);
                $this->updates         = [];

                return true;
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '_id_ dup key') !== false) {
                    if ($attempt === static::$maxRetryAttempts) {
                        $this->persisted       = true;
                        $this->recentlyUpdated = array_keys($valuesToSet);
                        $this->updates         = [];

                        return true;
                    }

                    return false;
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
        if (!$this->isPersisted() || $this->returnProperty(static::$timestampFields['delete']) !== null) {
            return true;
        }

        return false;
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
     * Serializes the object to a value that can be serialized natively by
     * `json_encode()`.
     *
     * @return mixed
     * @see    http://php.net/manual/en/jsonserializable.jsonserialize.php
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Gets a new instance of the model.
     * Override this method if you add required parameters to the model's
     * constructor, or if you have to pre-process the attributes before
     * instantiating the model instance.
     *
     * @param  array $attributes
     * @return static
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
                } else {
                    if ($i === 0 || $checkpoint) {
                        $finalField = $parentField;
                        $finalValue = $parentProperties;
                    }
                }
            }

            unset($parentProperties);
        }

        foreach ($this->updates as $updateField => $updateValue) {
            if (strpos($updateField, $finalField . '.') === 0) {
                unset($this->updates[$updateField]);
            }
        }

        $this->updates[$finalField] = $finalValue;
    }

    /**
     * Gets a new, pre-configured read preference object.
     *
     * @return ReadPreference
     * @throws InvalidArgumentException
     */
    protected static function readPreference()
    {
        return new ReadPreference(static::$readPreference, static::$readFromSets);
    }

    /**
     * Restores the object if it has been previously soft deleted.
     *
     * @return bool
     */
    public function restore()
    {
        if (!$this->isPersisted()) {
            return false;
        }

        if (!$this->isDeleted()) {
            return true;
        }

        $attempt = 1;
        $id      = $this->getId();

        do {
            try {
                $result = static::collection()->updateOne(
                    ['_id' => $id],
                    ['$unset' => [static::$timestampFields['delete'] => '']]
                );

                unset($this->properties[static::$timestampFields['delete']]);
                unset($this->updates[static::$timestampFields['delete']]);

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
        $this->beforeSave();

        if ($this->getId() === null) {
            $result = $this->insert();
        } else {
            $result = $this->upsert();
        }

        if ($result) {
            $this->afterSave($this->recentlyUpdated);
        }

        $this->recentlyUpdated = [];

        return $result;
    }

    /**
     * Sets the connection resolver instance.
     *
     * @param ConnectionResolverInterface $resolver
     */
    public static function setConnectionResolver(ConnectionResolverInterface $resolver)
    {
        static::$connectionResolver = $resolver;
    }

    /**
     * Soft deletes the object.
     *
     * @internal
     * @return bool
     */
    private function softDelete()
    {
        if (!$this->isPersisted()) {
            return false;
        }

        if ($this->isDeleted()) {
            return true;
        }

        $attempt = 1;
        $id      = $this->getId();

        do {
            try {
                $now = new DateTime();

                $result = static::collection()->updateOne(
                    ['_id' => $id],
                    [
                        '$set' => [
                            static::$timestampFields['delete'] => getBsonDateFromDateTime($now),
                        ],
                    ]
                );

                if ($result->isAcknowledged() && $result->getMatchedCount() === 0) {
                    $this->persisted = false;

                    return false;
                }

                $this->properties[static::$timestampFields['delete']] = $now;

                unset($this->updates[static::$timestampFields['delete']]);

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
     * Convert the object to its JSON representation.
     *
     * @param  int $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
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

            if (!$isFilling) {
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
            throw new RuntimeException(sprintf(
                'Tried to save an object (%s) with an invalid ID.',
                get_called_class()
            ));
        }

        if (empty($this->updates) && $this->isPersisted()) {
            return true;
        }

        if (static::$timestamps) {
            $this->updateProperty(static::$timestampFields['update'], new DateTime());

            if (!$this->isPersisted()) {
                $this->updateProperty(static::$timestampFields['create'], new DateTime());
            }
        }

        $valuesToSet   = convertDateTimeObjects($this->updates);
        $valuesToUnset = [];

        if (static::$unsetNulls) {
            foreach ($valuesToSet as $field => $value) {
                if ($value === null) {
                    $valuesToUnset[$field] = null;
                    unset($valuesToSet[$field]);
                }
            }
        }

        $updates = ['$set' => $valuesToSet];

        if (!empty($valuesToUnset)) {
            $updates['$unset'] = $valuesToUnset;
        }

        $attempt = 1;

        do {
            try {
                $result = static::collection()->updateOne(
                    ['_id' => $id],
                    $updates,
                    $this->isPersisted() ? [] : ['upsert' => true]
                );

                if ($result->isAcknowledged() && $result->getMatchedCount() === 0) {
                    $this->persisted = false;

                    return false;
                }

                $this->persisted       = true;
                $this->recentlyUpdated = array_keys(array_merge($valuesToSet, $valuesToUnset));
                $this->updates         = [];

                return true;
            } catch (Exception $e) {
                event(new WriteOperationFailed($e, $this));
            }

            $attempt++;
        } while ($attempt <= static::$maxRetryAttempts);

        return false;
    }

    /**
     * Validates the current property values of the model instance.
     *
     * @throws RuntimeException
     * @throws ValidationException
     */
    public function validate()
    {
        if (!empty(static::$validationRules)) {
            $validationFactory = app('validator');

            if (!$validationFactory instanceof ValidationFactory) {
                throw new RuntimeException('Unable to instantiate a validation factory.');
            }

            $validator = $validationFactory->make(
                $this->toArray(),
                static::$validationRules,
                static::$validationMessages
            );

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        }
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
