<?php
namespace UltimateMediaDeletion\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function testBasicAssertion()
    {
        $this->assertTrue(true, 'This should always pass');
    }
    
    public function testMathOperation()
    {
        $this->assertEquals(4, 2+2, 'Basic math should work');
    }
}