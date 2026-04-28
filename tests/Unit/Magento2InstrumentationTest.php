<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Magento2\Unit;

use PHPUnit\Framework\TestCase;

class Magento2InstrumentationTest extends TestCase
{
    public function testMagento2Instrumentation()
    {
        $this->assertSame('abc', 'abc');
    }
}
