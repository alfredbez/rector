<?php

declare(strict_types=1);

namespace Rector\Celebrity\Tests\Rector\FuncCall\SetTypeToCastRector;

use Iterator;
use Rector\Celebrity\Rector\FuncCall\SetTypeToCastRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class SetTypeToCastRectorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideDataForTest()
     */
    public function test(string $file): void
    {
        $this->doTestFile($file);
    }

    public function provideDataForTest(): Iterator
    {
        return $this->yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    protected function getRectorClass(): string
    {
        return SetTypeToCastRector::class;
    }
}
