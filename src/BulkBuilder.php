<?php

namespace Lindelius\LaravelMongo;

use MongoDB\BulkWriteResult;
use MongoDB\Collection;
use RuntimeException;

/**
 * Class BulkBuilder
 *
 * @author  Tom Lindelius <tom.lindelius@vivamedia.se>
 * @package Lindelius\LaravelMongo
 * @version 0.3
 */
class BulkBuilder
{
    /**
     * The collection on which to execute the bulk write.
     *
     * @internal
     * @var Collection|null
     */
    private $collection = null;

    /**
     * The operations to perform when executing the bulk write.
     *
     * @internal
     * @var array
     */
    private $operations = [];

    /**
     * The number of operations that have been added to the bulk.
     *
     * @internal
     * @var int
     */
    private $operationsCount = 0;

    /**
     * Constructor for BulkBuilder objects.
     *
     * @param Collection $collection
     */
    public function __construct(Collection $collection = null)
    {
        $this->collection = $collection;
    }

    /**
     * Adds an object to the bulk.
     *
     * @param Model $object
     */
    public function add(Model $object)
    {
        if ($this->collection === null) {
            $this->collection = $object::collection();
        }

        $bulkOperation = $object->asBulkOperation();

        if ($bulkOperation !== null) {
            $this->addRaw($bulkOperation);
        }
    }

    /**
     * Adds a raw bulk operation.
     *
     * @internal
     * @param array $operation
     */
    private function addRaw(array $operation)
    {
        $this->operations[] = $operation;

        $this->operationsCount++;
    }

    /**
     * Gets the number of operations that have been added to the bulk.
     *
     * @return int
     */
    public function count()
    {
        return $this->operationsCount;
    }

    /**
     * Adds a "delete many" bulk operation.
     *
     * @param array $filter
     */
    public function deleteMany(array $filter)
    {
        $this->addRaw([
            'deleteMany' => [
                $filter
            ]
        ]);
    }

    /**
     * Adds a "delete one" bulk operation.
     *
     * @param array $filter
     */
    public function deleteOne(array $filter)
    {
        $this->addRaw([
            'deleteOne' => [
                $filter
            ]
        ]);
    }

    /**
     * Executes the bulk write.
     *
     * @param  array $options
     * @return BulkWriteResult
     * @throws RuntimeException
     */
    public function execute(array $options = [])
    {
        if (!$this->collection instanceof Collection) {
            throw new RuntimeException('Tried to execute the bulk write on an invalid collection.');
        }

        $this->collection->bulkWrite($this->operations, $options);
    }

    /**
     * Adds a "insert one" bulk operation.
     *
     * @param array $document
     */
    public function insertOne(array $document)
    {
        $this->addRaw([
            'insertOne' => [
                $document
            ]
        ]);
    }

    /**
     * Adds a "replace one" bulk operation.
     *
     * @param array $filter
     * @param array $replacement
     * @param array $options
     */
    public function replaceOne(array $filter, array $replacement, array $options = [])
    {
        $this->addRaw([
            'replaceOne' => [
                $filter,
                $replacement,
                $options
            ]
        ]);
    }

    /**
     * Sets the collection on which to execute the bulk write.
     *
     * @param Collection $collection
     */
    public function setCollection(Collection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Adds a "update many" bulk operation.
     *
     * @param array $filter
     * @param array $update
     * @param array $options
     */
    public function updateMany(array $filter, array $update, array $options = [])
    {
        $this->addRaw([
            'updateMany' => [
                $filter,
                $update,
                $options
            ]
        ]);
    }

    /**
     * Adds a "update one" bulk operation.
     *
     * @param array $filter
     * @param array $update
     * @param array $options
     */
    public function updateOne(array $filter, array $update, array $options = [])
    {
        $this->addRaw([
            'updateOne' => [
                $filter,
                $update,
                $options
            ]
        ]);
    }
}
