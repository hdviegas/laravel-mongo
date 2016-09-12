<?php

namespace Lindelius\LaravelMongo\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use stdClass;

class TestCase extends BaseTestCase
{
    public function getDeeplyNestedObject($nestingLevel = null)
    {
        if (!is_int($nestingLevel)) {
            $nestingLevel = 20;
        }

        $object = [];
        $ref    = &$object;

        for ($i = 0; $i < $nestingLevel; $i++) {
            $ref = &$ref['a'];
        }

        return $object;
    }

    public function getInvalidDatabaseNames()
    {
        return [
            [new stdClass()],
            [['array']],
            [1],
            [0.1],
            [null]
        ];
    }

    public function getInvalidFieldKeys()
    {
        return [
            [new stdClass()],
            [['array']],
            [1],
            [0.1],
            [null]
        ];
    }

    public function getInvalidHosts()
    {
        return [
            [new stdClass()],
            [1],
            [0.1],
            [null]
        ];
    }
}
