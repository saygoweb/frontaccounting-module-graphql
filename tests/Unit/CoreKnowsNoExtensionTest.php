<?php

namespace FA\GraphQL\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Release 4 spec §1 "Success": the module's source knows no extension. Recurrence is
 * sgw_sales' (its GraphQL extension); nothing here may name it.
 */
class CoreKnowsNoExtensionTest extends TestCase
{
    private const FORBIDDEN = ['sgw_sales', 'sales_recurring', 'Recurrence', 'RecurringSchedule'];

    public function testTheModuleSourceNamesNoExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/container.php', $root . '/app.php'];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $found = [];
        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::FORBIDDEN as $word) {
                if (stripos($source, $word) !== false) {
                    $found[] = substr($path, strlen($root) + 1) . ": $word";
                }
            }
        }
        $this->assertSame([], $found);
    }
}
