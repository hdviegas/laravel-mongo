<?php

namespace Lindelius\LaravelMongo;

use MongoDB\BulkWriteResult;
use MongoDB\Collection;
use RuntimeException;

/**
 * Class BulkBuilder
 *
 * @author  Tom Lindelius <tom.lindelius@vivamedia.se>
 * @version 2016-11-23
 */
class BulkBuilder
{
    /**
     * The collection on which we should execute the bulk write.
     *
     * @var Collection|null
     */
    private $collection = null;

    /**
     * The operations to perform when executing the bulk write.
     *
     * @var array
     */
    private $operations = [];

    /**
     * The number of operations that have been added to the bulk.
     *
     * @var int
     */
    private $operationsCount = 0;

    /**
     * Constructor for BulkBuilder objects.
     */
    public function __construct()
    {
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

        $this->addRaw($object->asBulkOperation());
    }

    /**
     * Adds a raw operation to the bulk.
     *
     * @param array $operation
     */
    public function addRaw(array $operation)
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
}
