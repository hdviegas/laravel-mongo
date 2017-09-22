<?php

namespace Lindelius\LaravelMongo;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;

/**
 * Class MongoDbConnection
 *
 * @author  Tom Lindelius <tom.lindelius@gmail.com>
 * @package Lindelius\LaravelMongo
 * @version 0.3
 */
class MongoDbConnection extends Connection
{
    /**
     * The MongoDB client.
     *
     * @var Client
     */
    protected $mongoClient;

    /**
     * The current MongoDB database object.
     *
     * @var Database
     */
    protected $mongoDatabase;

    /**
     * Create a new database connection instance.
     *
     * @param  array $config
     * @throws InvalidArgumentException
     */
    public function __construct(array $config)
    {
        if (empty($config['database']) || !is_string($config['database'])) {
            throw new InvalidArgumentException('The database name must be a valid string.');
        }

        $this->config        = $config;
        $this->database      = $config['database'];
        $this->mongoClient   = $this->createClient($config);
        $this->mongoDatabase = $this->mongoClient->selectDatabase($config['database']);
    }

    /**
     * Dynamically pass calls to non-implemented methods to the underlying
     * `MongoDB\Database` instance.
     *
     * @param  string $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return call_user_func_array([$this->mongoDatabase, $method], $parameters);
    }

    /**
     * Creates a MongoDB client.
     *
     * @param  array $config
     * @return Client
     * @throws InvalidArgumentException
     */
    protected function createClient(array $config)
    {
        if (empty($config['hosts']) || (!is_string($config['hosts']) && !is_array($config['hosts']))) {
            throw new InvalidArgumentException('The host must be a valid string, or an array.');
        }

        if (empty($config['database']) || !is_string($config['database'])) {
            throw new InvalidArgumentException('The database name must be a valid string.');
        }

        if (is_array($config['hosts'])) {
            $config['host'] = implode(',', $config['hosts']);
        }

        $defaultDriverOptions = [
            'typeMap' => [
                'root'     => 'array',
                'document' => 'array',
                'array'    => 'array',
            ],
        ];

        $defaultUriOptions = [
            'authSource'       => 'admin',
            'connectTimeoutMS' => 5000,
        ];

        if (!empty($config['username']) && !empty($config['password'])) {
            $defaultUriOptions['username'] = $config['username'];
            $defaultUriOptions['password'] = $config['password'];
        }

        return new Client(
            sprintf('mongodb://%s/', $config['hosts']),
            array_merge($defaultUriOptions, $config['uriOptions']),
            array_merge($defaultDriverOptions, $config['driverOptions'])
        );
    }

    /**
     * Gets the MongoDB client instance.
     *
     * @return Client
     */
    public function getClient()
    {
        return $this->mongoClient;
    }

    /**
     * Gets a `MongoDB\Collection` instance for a given collection in the
     * current database.
     *
     * @param  string $collection
     * @return Collection
     */
    public function getCollection($collection)
    {
        return $this->mongoDatabase->selectCollection($collection);
    }

    /**
     * Gets the current `MongoDB\Database` instance.
     *
     * @return Database
     */
    public function getDatabase()
    {
        return $this->mongoDatabase;
    }

    /**
     * Connects to a given MongoDB database.
     *
     * @param string $database
     */
    public function setDatabase($database)
    {
        $this->database      = $database;
        $this->mongoDatabase = $this->mongoClient->selectDatabase($database);
    }

    /**
     * Connects to a given MongoDB database.
     *
     * @param  string $database
     * @return string
     * @see    MongoDbConnection::setDatabase()
     */
    public function setDatabaseName($database)
    {
        $this->setDatabase($database);

        return $database;
    }
}
