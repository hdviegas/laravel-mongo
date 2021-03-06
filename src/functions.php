<?php

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\UTCDateTime;

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
                    throw new RuntimeException(sprintf(
                        'The field key "%s" must neither contain dots nor start with a dollar sign.',
                        $key
                    ));
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
        } else if (is_object($object)) {
            foreach ($object as $key => $value) {
                if ($value instanceof UTCDateTime) {
                    $object->{$key} = $value->toDateTime();
                } else if (is_array($value) || is_object($value)) {
                    $object->{$key} = convertBsonDateObjects($value, $nestingLevel);
                }
            }
        } else if (is_array($object)) {
            foreach ($object as $key => $value) {
                if ($value instanceof UTCDateTime) {
                    $object[$key] = $value->toDateTime();
                } else if (is_array($value) || is_object($value)) {
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
        } else if (is_object($object)) {
            foreach ($object as $key => $value) {
                if ($value instanceof DateTime) {
                    $object->{$key} = getBsonDateFromDateTime($value);
                } else if (is_array($value) || is_object($value)) {
                    $object->{$key} = convertDateTimeObjects($value, $nestingLevel);
                }
            }
        } else if (is_array($object)) {
            foreach ($object as $key => $value) {
                if ($value instanceof DateTime) {
                    $object[$key] = getBsonDateFromDateTime($value);
                } else if (is_array($value) || is_object($value)) {
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
     * @param  DateTime $date
     * @return UTCDateTime
     */
    function getBsonDateFromDateTime(DateTime $date)
    {
        $milliseconds = (float) substr((string) $date->format('Uu'), 0, -3);

        return new UTCDateTime($milliseconds);
    }
}

if (!function_exists('getTimestampFromBsonDate')) {
    /**
     * Converts a `MongoDB\BSON\UTCDateTime` object to a UNIX timestamp.
     *
     * @param  UTCDateTime $date
     * @return int
     */
    function getTimestampFromBsonDate(UTCDateTime $date)
    {
        return (int) substr((string) $date, 0, -3);
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
            throw new InvalidArgumentException('The field key must be either a valid string or an integer.');
        }

        $value = trim($value);

        if (strpos($value, '.') !== false || $value[0] === '$') {
            if ($cleanUpKey) {
                $value = trim(preg_replace(['/\./u', '/^\$/u'], '', $value));
            } else {
                throw new InvalidArgumentException(sprintf(
                    'The field key "%s" must neither contain dots nor start with a dollar sign.',
                    $value
                ));
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
            if ($object instanceof ObjectId || $object instanceof Timestamp || $object instanceof UTCDateTime) {
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
