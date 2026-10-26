<?php

declare(strict_types=1);

use Statamic\Facades\AssetContainer;
use UAMSWeb\FormSettings\Tests\TestCase;

/**
 * A parallel worker's asset containers stay in a directory of its own (#19).
 *
 * Statamic's test case points every worker at one container directory. A container saved on a
 * disk only the saving worker has faked then breaks any other worker that loads the store, so
 * the store is split per worker token.
 *
 * @command  vendor/bin/pest tests/Integration/AssetContainerIsolationTest.php
 */
it('names a directory per worker token beside the shared one', function (): void {
    expect(TestCase::assetContainersPath('/fixtures/content/assets', '3'))->toBe('/fixtures/content/assets-3')
        ->and(TestCase::assetContainersPath('/fixtures/content/assets/', 12))->toBe('/fixtures/content/assets-12')
        ->and(TestCase::assetContainersPath('/fixtures/content/assets', null))->toBeNull()
        ->and(TestCase::assetContainersPath(null, '3'))->toBeNull();
});

it('does not load a container another worker left in the shared directory', function (): void {
    $token = $_SERVER['TEST_TOKEN'] ?? null;
    $directory = config('statamic.stache.stores.asset-containers.directory');
    if (! is_string($directory)) {
        throw new RuntimeException('The asset container directory is not configured.');
    }

    // A serial run has one worker and so no neighbor to isolate from; the directory stays unsplit
    if (! is_string($token) && ! is_int($token)) {
        expect($directory)->not->toMatch('/-\d+$/');

        return;
    }

    $suffix = "-{$token}";
    expect($directory)->toEndWith($suffix);

    // A neighbor's container, left in the directory Statamic's test case shares
    $shared = substr($directory, 0, -strlen($suffix));
    $strayHandle = 'stray_neighbor_'.uniqid();
    @mkdir($shared, 0777, true);
    file_put_contents("{$shared}/{$strayHandle}.yaml", "title: Stray\ndisk: neighbor_only_disk\n");

    try {
        expect(AssetContainer::all()->map->handle()->all())->not->toContain($strayHandle);
    } finally {
        @unlink("{$shared}/{$strayHandle}.yaml");
    }
});
