<?php
use PHPUnit\Framework\TestCase;

require_once 'vendor/autoload.php';
require_once 'src/globals.php';

class GlobalTest extends TestCase
{
    public function testDejoin()
    {
        $this->assertEquals(
            array('first', 'second', 'third'),
            dejoin('/', 'first/second/third'),
            'Full string returns an array with parts'
        );

        $this->assertEquals(
            array(),
            dejoin('/', ''),
            'Empty string returns an empty array'
        );

        $this->assertEquals(
            array(),
            dejoin('/', null),
            'NULL returns an empty array'
        );
    }

    public function testArrayMergeIndexed()
    {
        // TODO

    }
}
