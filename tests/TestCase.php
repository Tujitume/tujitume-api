<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** This process's own storage root for Storage::fake() disks */
    private ?string $fakeStorageRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Storage::fake('s3') empties storage/framework/testing/disks/s3. Parallel workers get their own folder, but
        // several test runs at once (more than one session testing) shared it and wiped each other's files mid-test
        // (random 404s in ApplicationFileTest). Each process now fakes its disks under its own folder. Config, logs and
        // compiled views were resolved at boot, so only later storage_path() calls (the fake disks) move.
        $this->fakeStorageRoot = storage_path('framework/testing/run-'.getmypid());
        $this->app->useStoragePath($this->fakeStorageRoot);

        // Every controller reads the USD/KES rate when it is built (Controller::__construct). Tests never call the
        // real rates API (it hung ReviewerListsTest past PHP's 120 s limit): a fixed 130 KES per USD, unless a test
        // binds its own converter.
        $this->app->instance(\App\Service\Balance\CurrencyConverter::class, new class extends \App\Service\Balance\CurrencyConverter {
            public function UsdToKes() { return 130; }
            public function KesToUsd() { return 1 / 130; }
        });
    }

    protected function tearDown(): void
    {
        $root = $this->fakeStorageRoot;
        parent::tearDown();
        if ($root && is_dir($root)) {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
