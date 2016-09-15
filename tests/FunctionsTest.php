<?php

namespace Lindelius\LaravelMongo\Tests;

use DateTime;
use InvalidArgumentException;
use MongoDB\Database;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

/**
 * Unit tests for the various helper functions included with this library.
 */
class FunctionsTest extends TestCase
{
    /**
     * Tests the `convertBsonDateObjects()` function and makes sure that it properly converts the `UTCDateTime` objects.
     */
    public function testConvertBsonDateObjects()
    {
        $object = [
            'date' => new UTCDatetime(time() * 1000),
            'field' => [
                'date' => new UTCDatetime(time() * 1000),
                'field' => [
                    'date' => new UTCDatetime(time() * 1000)
                ]
            ]
        ];

        $convertedObject = convertBsonDateObjects($object);

        $this->assertInstanceOf(DateTime::class, $convertedObject['date']);
        $this->assertInstanceOf(DateTime::class, $convertedObject['field']['date']);
        $this->assertInstanceOf(DateTime::class, $convertedObject['field']['field']['date']);
    }

    /**
     * Tests the `convertBsonDateObjects()` function and makes sure that it throws an error when iterating over objects
     * that are too deeply nested.
     *
     * @expectedException RuntimeException
     */
    public function testConvertBsonDateObjectsForTooDeeplyNestedObject()
    {
        $nestingLevel = 11;
        $nestedObject = $this->getDeeplyNestedObject($nestingLevel);

        convertBsonDateObjects($nestedObject);
    }

    /**
     * Tests the `convertDateTimeObjects()` function and makes sure that it properly converts the `DateTime` objects.
     */
    public function testConvertDateTimeObjects()
    {
        $object = [
            'date' => new Datetime(),
            'field' => [
                'date' => new Datetime(),
                'field' => [
                    'date' => new Datetime()
                ]
            ]
        ];

        $convertedObject = convertDateTimeObjects($object);

        $this->assertInstanceOf(UTCDatetime::class, $convertedObject['date']);
        $this->assertInstanceOf(UTCDatetime::class, $convertedObject['field']['date']);
        $this->assertInstanceOf(UTCDatetime::class, $convertedObject['field']['field']['date']);
    }

    /**
     * Tests the `convertDateTimeObjects()` function and makes sure that it throws an error when iterating over objects
     * that are too deeply nested.
     *
     * @expectedException RuntimeException
     */
    public function testConvertDateTimeObjectsForTooDeeplyNestedObject()
    {
        $nestingLevel = 11;
        $nestedObject = $this->getDeeplyNestedObject($nestingLevel);

        convertDateTimeObjects($nestedObject);
    }

    /**
     * Tests the `getBsonDateFromDateTime()` function and makes sure it properly converts the given `DateTime` object.
     */
    public function testGetBsonDateFromDateTime()
    {
        $timestamp = time();

        $dateTime = new DateTime();
        $dateTime->setTimestamp($timestamp);

        $bsonDateTime  = getBsonDateFromDateTime($dateTime);
        $bsonTimestamp = substr((string) $bsonDateTime, 0, -3);

        $this->assertEquals($timestamp, (float) $bsonTimestamp);
    }

    /**
     * Tests the `getMongoDb()` function and makes sure that it returns a `Database` object.
     */
    public function testGetMongoDb()
    {
        $database = getMongoDb('127.0.0.1:27017', 'test');

        $this->assertInstanceOf(Database::class, $database);
    }

    /**
     * Tests the `getMongoDb()` function and makes sure that it throws an error when given an invalid database name.
     *
     * @dataProvider      getInvalidDatabaseNames
     * @expectedException InvalidArgumentException
     */
    public function testGetMongoDbWithInvalidDatabaseName($databaseName)
    {
        getMongoDb('127.0.0.1:27017', $databaseName);
    }

    /**
     * Tests the `getMongoDb()` function and makes sure that it throws an error when given an invalid host.
     *
     * @dataProvider      getInvalidHosts
     * @expectedException InvalidArgumentException
     */
    public function testGetMongoDbWithInvalidHost($host)
    {
        getMongoDb($host, 'test');
    }

    /**
     * Tests the `sanitizeFieldKey()` function and makes sure that it cleans up invalid characters.
     */
    public function testSanitizeFieldKeyWithCleanUp()
    {
        $key = sanitizeFieldKey('    $s3ttings.$email.#=Test.. ', true);

        $this->assertEquals('s3ttings$email#=Test', $key);
    }

    /**
     * Tests the `sanitizeFieldKey()` function and makes sure that it throws an error when given an invalid field key.
     *
     * @dataProvider      getInvalidFieldKeys
     * @expectedException InvalidArgumentException
     */
    public function testSanitizeFieldKeyWithInvalidKey($key)
    {
        sanitizeFieldKey($key);
    }

    /**
     * Tests the `sanitizeFieldKey()` function and makes sure that it throws an error if `$cleanUp` is set to `false`
     * and the key contains invalid characters.
     *
     * @expectedException InvalidArgumentException
     */
    public function testSanitizeFieldKeyWithoutCleanUp()
    {
        sanitizeFieldKey('    $s3ttings.$email.#=Test.. ', false);
    }

    /**
     * Tests the `sanitizeFieldValue()` function and makes sure that it cleans up nested field keys that contains
     * invalid characters.
     */
    public function testSanitizeFieldValueWithBadNestedFieldKeyAndWithCleanUp()
    {
        $object = [
            'field' => [
                '$set' => [
                    'another_field' => true
                ]
            ]
        ];

        $object = sanitizeFieldValue($object, true, true);

        $this->assertFalse(isset($object['field']['$set']));
        $this->assertTrue(isset($object['field']['set']['another_field']));
    }

    /**
     * Tests the `sanitizeFieldValue()` function and makes sure that it throws an error if `$cleanUpKeys` is set to
     * `false` and the value contains nested fields with invalid field keys.
     *
     * @expectedException InvalidArgumentException
     */
    public function testSanitizeFieldValueWithBadNestedFieldKeyAndWithoutCleanUp()
    {
        $object = [
            'field' => [
                '$set' => [
                    'field' => true
                ]
            ]
        ];

        sanitizeFieldValue($object, true, false);
    }

    /**
     * Tests the `sanitizeFieldValue()` function and makes sure that it properly sanitizes and returns a given value
     * with `$trimAndCompact` set to `true`.
     *
     * @dataProvider getSanitizeFieldValueDataWithTrimAndCompact
     */
    public function testSanitizeFieldValueWithTrimAndCompact($expected, $value)
    {
        $value = sanitizeFieldValue($value, true);

        $this->assertEquals($expected, $value);
    }

    /**
     * Tests the `sanitizeFieldValue()` function and makes sure that it properly sanitizes and returns a given value
     * with `$trimAndCompact` set to `false`.
     *
     * @dataProvider getSanitizeFieldValueDataWithoutTrimAndCompact
     */
    public function testSanitizeFieldValueWithoutTrimAndCompact($expected, $value)
    {
        $value = sanitizeFieldValue($value, false);

        $this->assertEquals($expected, $value);
    }

    /**
     * Data provider for the `testSanitizeFieldValueWithTrimAndCompact()` unit test.
     */
    public function getSanitizeFieldValueDataWithTrimAndCompact()
    {
        return [
            ['trim and compact', '  trim   and  compact '],
            ['&lt;div &gt;', '    <div  >'],
            [1, 1],
            [0.1, 0.1],
            [true, true]
        ];
    }

    /**
     * Data provider for the `testSanitizeFieldValueWithoutTrimAndCompact()` unit test.
     */
    public function getSanitizeFieldValueDataWithoutTrimAndCompact()
    {
        return [
            ['  trim   and  compact ', '  trim   and  compact '],
            ['    &lt;div  &gt;', '    <div  >'],
            [1, 1],
            [0.1, 0.1],
            [true, true]
        ];
    }
}
