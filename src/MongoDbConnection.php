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
 * @version 0.1
 * @package Lindelius\LaravelMongo
 */
class MongoDbConnection extends Connection
{
    /**
     * @var Client
     */
    protected $mongoClient;

    /**
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
        $this->mongoClient   = $this->createConnection($config);
        $this->mongoDatabase = $this->mongoClient->selectDatabase($config['database']);
    }

    /**
     * Dynamically pass methods to the connection.
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
     * Gets a collection instance for a given collection in the current database.
     *
     * @param  string $collection
     * @return Collection
     */
    public function collection($collection)
    {
        return $this->mongoDatabase->selectCollection($collection);
    }

    /**
     * Creates a MongoDB client instance.
     *
     * @param  array $config
     * @return Client
     * @throws InvalidArgumentException
     */
    protected function createConnection(array $config)
    {
        if (empty($config['hosts']) || (!is_string($config['hosts']) && !is_array($config['hosts']))) {
            throw new InvalidArgumentException('The host must be a valid string, or an array.');
        }

        if (empty($config['database']) || !is_string($config['database'])) {
            throw new InvalidArgumentException('The database name must be a valid string.');
        }

        $defaultDriverOptions = [
            'typeMap' => [
                'root'     => 'array',
                'document' => 'array',
                'array'    => 'array'
            ]
        ];

        $defaultUriOptions = [
            'authSource'       => 'admin',
            'connectTimeoutMS' => 5000
        ];

        if (!empty($config['username']) && !empty($config['password'])) {
            $defaultUriOptions['username'] = $config['username'];
            $defaultUriOptions['password'] = $config['password'];
        }

        return new Client(
            sprintf('mongodb://%s/', is_array($config['hosts']) ? implode(',', $config['hosts']) : $config['hosts']),
            array_merge($defaultUriOptions, $config['uriOptions']),
            array_merge($defaultDriverOptions, $config['driverOptions'])
        );
    }

    /**
     * Gets the MongoDB client instance.
     *
     * @return Client
     */
    public function getMongoClient()
    {
        return $this->mongoClient;
    }

    /**
     * Gets the MongoDB database instance.
     *
     * @return Database
     */
    public function getMongoDb()
    {
        return $this->mongoDatabase;
    }

    /**
     * Sets the name of the connected database.
     *
     * @param  string $database
     * @return string
     */
    public function setDatabaseName($database)
    {
        $this->database      = $database;
        $this->mongoDatabase = $this->mongoClient->selectDatabase($this->database);

        return $this->database;
    }

    /**
     * Gets a collection instance for a given collection in the current database.
     *
     * @param  string $table
     * @return Collection
     * @see    MongoDbConnection::collection()
     */
    public function table($table)
    {
        return $this->collection($table);
    }
}
