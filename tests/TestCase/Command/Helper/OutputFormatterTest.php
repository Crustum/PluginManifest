<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Command\Helper;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Command\Helper\OutputFormatter;

/**
 * Unit tests for OutputFormatter path truncation.
 */
class OutputFormatterTest extends TestCase
{
    public function testTruncatePathLeavesShortPaths(): void
    {
        $formatter = new OutputFormatter();

        $this->assertSame('config/app.php', $formatter->truncatePath('config/app.php', 60));
    }

    public function testTruncatePathShortensLongPaths(): void
    {
        $formatter = new OutputFormatter();
        $path = str_repeat('a' . DS, 40) . 'broadcasting.php';
        $truncated = $formatter->truncatePath($path, 40);

        $this->assertStringStartsWith('...', $truncated);
        $this->assertStringEndsWith('broadcasting.php', $truncated);
        $this->assertLessThanOrEqual(40, strlen($truncated));
    }

    public function testTruncatePathEmpty(): void
    {
        $formatter = new OutputFormatter();

        $this->assertSame('', $formatter->truncatePath('', 60));
    }
}
