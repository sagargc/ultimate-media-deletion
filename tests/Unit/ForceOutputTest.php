<?php
namespace UltimateMediaDeletion\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ForceOutputTest extends TestCase {
    public function testForceOutput() {
        print "=== TEST OUTPUT FORCED ===\n";
        $this->assertTrue(true);
    }
}