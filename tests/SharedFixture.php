<?php

declare(strict_types=1);

namespace Kawaa\Tests;

/**
 * Finds the test fixtures that every Kawaa SDK shares: tests/testdata when
 * this directory has one, ../../testdata otherwise.
 */
final class SharedFixture
{
    public static function path(string $relative): string
    {
        $root = is_dir(__DIR__ . '/testdata') ? __DIR__ . '/testdata' : __DIR__ . '/../../testdata';

        return $root . '/' . $relative;
    }
}
