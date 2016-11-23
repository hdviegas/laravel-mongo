<?php

use MongoDB\BSON\ObjectID;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Database;

if (!function_exists('checkNestedFieldNames')) {
    /**
     * Checks nested field names of an object, making sure they only contain valid characters.
     *
     * @param  mixed $object
     * @param  int   $nestingLevel
     * @throws RuntimeException
     */
    function checkNestedFieldNames($object, $nestingLevel = 0)
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
                    checkNestedFieldNames($value, $nestingLevel);
                }
            }
        }
    }
}

if (!function_exists('convertBsonDateObjects')) {
    /**
     * Searches for and converts all `MongoDB\BSON\UTCDateTime` objects to `DateTime` objects.
     *
     * @param  mixed $object
     * @param  int   $nestingLevel
     * @return mixed
     * @throws RuntimeException
     */
    function convertBsonDateObjects($object, $nestingLevel = 0)
    {
        $nestingLevel++;

        if ($nestingLevel > 10) {
            throw new RuntimeException('The object is nested too deep.');
        }

        if ($object instanceof UTCDateTime) {
            $object = $object->toDateTime();
        } elseif (is_object($object)) {
            foreach ($object as $key => $value) {
                if ($value instanceof UTCDateTime) {
                    $object->{$key} = $value->toDateTime();
                } elseif (is_array($value) || is_object($value)) {
                    $object->{$key} = convertBsonDateObjects($value, $nestingLevel);
                }
            }
        } elseif (is_array($object)) {
            foreach ($object as $key => $value) {
                if ($value instanceof UTCDateTime) {
                    $object[$key] = $value->toDateTime();
                } elseif (is_array($value) || is_object($value)) {
                    $object[$key] = convertBsonDateObjects($value, $nestingLevel);
                }
            }
        }

        return $object;
    }
}

if (!function_exists('convertDateTimeObjects')) {
    /**
     * Searches for and converts all `DateTime` objects to `MongoDB\BSON\UTCDateTime` objects.
     *
     * @param  mixed $object
     * @param  int   $nestingLevel
     * @return mixed
     * @throws RuntimeException
     */
    function convertDateTimeObjects($object, $nestingLevel = 0)
    {
        $nestingLevel++;

        if ($nestingLevel > 10) {
            throw new RuntimeException('The object is nested too deep.');
        }

        if ($object instanceof DateTime) {
            $object = getBsonDateFromDateTime($object);
        } elseif (is_object($object)) {
            foreach ($object as $key => $value) {
                if ($value instanceof DateTime) {
                    $object->{$key} = getBsonDateFromDateTime($value);
                } elseif (is_array($value) || is_object($value)) {
                    $object->{$key} = convertDateTimeObjects($value, $nestingLevel);
                }
            }
        } elseif (is_array($object)) {
            foreach ($object as $key => $value) {
                if ($value instanceof DateTime) {
                    $object[$key] = getBsonDateFromDateTime($value);
                } elseif (is_array($value) || is_object($value)) {
                    $object[$key] = convertDateTimeObjects($value, $nestingLevel);
                }
            }
        }

        return $object;
    }
}

if (!function_exists('getBsonDateFromDateTime')) {
    /**
     * Converts a `DateTime` object to a `MongoDB\BSON\UTCDateTime` object.
     *
     * @param  DateTime $dateTime
     * @return UTCDateTime
     */
    function getBsonDateFromDateTime(DateTime $dateTime)
    {
        $milliseconds = (float) substr((string) $dateTime->format('Uu'), 0, -3);

        return new UTCDateTime($milliseconds);
    }
}

if (!function_exists('getMongoDb')) {
    /**
     * Gets a fully configured `MongoDB\Database` object.
     *
     * @param  array|string $host
     * @param  string       $database
     * @param  string       $username
     * @param  string       $password
     * @param  array        $uriOptions
     * @param  array        $driverOptions
     * @return Database
     * @throws InvalidArgumentException
     */
    function getMongoDb($host, $database, $username = null, $password = null, array $uriOptions = [], array $driverOptions = [])
    {
        if (empty($host) || (!is_string($host) && !is_array($host))) {
            throw new InvalidArgumentException('The host must be a valid string, or an array.');
        }

        if (empty($database) || !is_string($database)) {
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
            'authSource'       => $database,
            'connectTimeoutMS' => 5000
        ];

        if (!empty($username) && !empty($password)) {
            $defaultUriOptions['username'] = $username;
            $defaultUriOptions['password'] = $password;
        }

        $client = new Client(
            sprintf('mongodb://%s/', is_array($host) ? implode(',', $host) : $host),
            array_merge($defaultUriOptions, $uriOptions),
            array_merge($defaultDriverOptions, $driverOptions)
        );

        return $client->selectDatabase($database);
    }
}

if (!function_exists('getTimestampFromBsonDate')) {
    /**
     * Converts a `MongoDB\BSON\UTCDateTime` object to a UNIX timestamp.
     *
     * @param  UTCDateTime $bsonDate
     * @return int
     */
    function getTimestampFromBsonDate(UTCDateTime $bsonDate)
    {
        return (int) substr((string) $bsonDate, 0, -3);
    }
}

if (!function_exists('sanitizeFieldKey')) {
    /**
     * Sanitizes a given value, making it safe for insertion into MongoDB as a field key.
     *
     * @param  string|int $value
     * @param  bool       $cleanUpKey
     * @return mixed
     * @throws InvalidArgumentException
     */
    function sanitizeFieldKey($value, $cleanUpKey = true)
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('The field key must be a valid string or an integer.');
        }

        $value = trim($value);

        if (strpos($value, '.') !== false || $value[0] === '$') {
            if ($cleanUpKey) {
                $value = trim(preg_replace(['/\./u', '/^\$/u'], '', $value));
            } else {
                throw new InvalidArgumentException('The field key must not contain any dots, or start with a dollar sign.');
            }
        }

        return $value;
    }
}

if (!function_exists('sanitizeFieldValue')) {
    /**
     * Sanitizes a given object, making it safe for insertion into MongoDB as a field value.
     *
     * @param  mixed $object
     * @param  bool  $trimAndCompact
     * @param  bool  $cleanUpKeys
     * @return mixed
     * @throws InvalidArgumentException
     */
    function sanitizeFieldValue($object, $trimAndCompact = true, $cleanUpKeys = true)
    {
        if (is_string($object)) {
            $object = preg_replace(['/</u', '/>/u'], ['&lt;', '&gt;'], $object);

            if ($trimAndCompact) {
                $object = trim(preg_replace('/\s\s+/u', ' ', $object));
            }

            return $object;
        }

        if (is_bool($object) || is_numeric($object)) {
            return $object;
        }

        if (is_array($object)) {
            foreach ($object as $key => $value) {
                $newKey   = sanitizeFieldKey($key, $cleanUpKeys);
                $newValue = sanitizeFieldValue($value, $trimAndCompact, $cleanUpKeys);

                if ($newKey !== $key) {
                    if (!isset($object[$newKey])) {
                        $object[$newKey] = $newValue;
                    }

                    unset($object[$key]);
                } else {
                    $object[$key] = $newValue;
                }
            }

            return $object;
        }

        if (is_object($object)) {
            if ($object instanceof ObjectID || $object instanceof Timestamp || $object instanceof UTCDateTime) {
                return $object;
            }

            foreach ($object as $key => $value) {
                $newKey   = sanitizeFieldKey($key, $cleanUpKeys);
                $newValue = sanitizeFieldValue($value, $trimAndCompact, $cleanUpKeys);

                if ($newKey !== $key) {
                    if (!isset($object->{$newKey})) {
                        $object->{$newKey} = $newValue;
                    }

                    unset($object->{$key});
                } else {
                    $object->{$key} = $newValue;
                }
            }

            return $object;
        }

        return null;
    }
}
