<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // Runs before any testing trait, so RefreshDatabase never reaches
        // migrate:fresh unless the live database name is approved.
        TestDatabaseGuard::assertIsolatedForTests($app);

        return $app;
    }
}
