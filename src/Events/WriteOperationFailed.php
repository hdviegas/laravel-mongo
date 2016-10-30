<?php

namespace Lindelius\LaravelMongo\Events;

use Exception;
use Lindelius\LaravelMongo\Model;

/**
 * Class WriteOperationFailed
 *
 * @author  Tom Lindelius <tom.lindelius@vivamedia.se>
 * @version 0.2
 */
class WriteOperationFailed
{
    /**
     * The exception object that was thrown when the write operation failed.
     *
     * @internal
     * @var Exception
     */
    private $exception;

    /**
     * The model object for which the write operation failed.
     *
     * @internal
     * @var Model
     */
    private $model;

    /**
     * Constructor for WriteOperationFailed objects.
     *
     * @param Exception $exception
     * @param Model     $model
     */
    public function __construct(Exception $exception, Model $model)
    {
        $this->exception = $exception;
        $this->model     = $model;
    }

    /**
     * Gets the exception object that was thrown when the write failed.
     *
     * @return Exception
     */
    public function getException()
    {
        return $this->exception;
    }

    /**
     * Gets the model object for which the write operation failed.
     *
     * @return Model
     */
    public function getModel()
    {
        return $this->model;
    }
}
