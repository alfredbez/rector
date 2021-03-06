<?php

declare(strict_types=1);

namespace Rector\CakePHPToSymfony\Tests\Rector\Echo_\CakePHPTemplateHToTwigRector;

use Iterator;
use Rector\CakePHPToSymfony\Rector\Echo_\CakePHPTemplateHToTwigRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class CakePHPTemplateHToTwigRectorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideDataForTest()
     */
    public function test(string $file): void
    {
        $this->doTestFileWithoutAutoload($file);
    }

    public function provideDataForTest(): Iterator
    {
        return $this->yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    protected function getRectorClass(): string
    {
        return CakePHPTemplateHToTwigRector::class;
    }
}
