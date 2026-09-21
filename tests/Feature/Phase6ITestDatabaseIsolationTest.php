<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use PDO;
use PDOStatement;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

/**
 * Task 12: prove that an automated test run can never point at the development
 * or the hardware-acceptance database.
 *
 * Phase 6I keeps three PostgreSQL databases on one cluster. RefreshDatabase
 * reaches `migrate:fresh` on the first test of a process, which drops every
 * table of whichever database the booted application resolved - so a silently
 * overriding DB_DATABASE (from the shell, from .env, or from a DSN) is enough to
 * destroy the acceptance fixtures that cost real hardware time to produce.
 *
 * There are two independent defences and this file pins both:
 *   1. phpunit.xml pins env and server values, so ambient values lose.
 *   2. Tests\Support\TestDatabaseGuard asks the server which database it is
 *      attached to on every boot and refuses anything not on the allow-list.
 *
 * Deliberately no RefreshDatabase/DatabaseTransactions here: a file that proves
 * isolation must not itself migrate or truncate anything.
 */
class Phase6ITestDatabaseIsolationTest extends TestCase
{
    public function test_only_the_automated_test_database_is_approved(): void
    {
        $this->assertSame('cosmiclink_test', TestDatabaseGuard::APPROVED_DATABASE);
        $this->assertSame([TestDatabaseGuard::APPROVED_DATABASE], TestDatabaseGuard::approvedDatabases());
        $this->assertSame(
            ['cosmiclink', 'cosmiclink_phase6i_acceptance'],
            TestDatabaseGuard::PROTECTED_DATABASES
        );
        $this->assertEmpty(
            array_intersect(TestDatabaseGuard::PROTECTED_DATABASES, TestDatabaseGuard::approvedDatabases()),
            'A database cannot be both protected and approved for automated tests.'
        );
    }

    public function test_the_run_is_actually_attached_to_the_approved_database(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame(
            TestDatabaseGuard::APPROVED_DATABASE,
            config('database.connections.pgsql.database')
        );

        // The name the server reports, not the name the configuration claims.
        $live = $this->app['db']->getPdo()->query('select current_database()')->fetchColumn();

        $this->assertSame(TestDatabaseGuard::APPROVED_DATABASE, $live);
    }

    public function test_the_guard_cleared_this_run_rather_than_being_bypassed(): void
    {
        $this->assertGreaterThanOrEqual(
            1,
            TestDatabaseGuard::verificationCount(),
            'Tests\TestCase::createApplication() must verify the target database on every boot.'
        );
    }

    public function test_guard_refuses_the_development_database(): void
    {
        $message = $this->guardRefusalFor(['database' => 'cosmiclink']);

        $this->assertStringContainsString('refusing to run automated tests', $message);
        $this->assertStringContainsString('is not an approved automated-test database', $message);
        $this->assertStringContainsString('target:      cosmiclink', $message);
        $this->assertStringContainsString('PROTECTED:   cosmiclink (development data)', $message);
        $this->assertStringContainsString('cosmiclink_test', $message);
    }

    public function test_guard_refuses_the_hardware_acceptance_database(): void
    {
        $message = $this->guardRefusalFor(['database' => 'cosmiclink_phase6i_acceptance']);

        $this->assertStringContainsString('refusing to run automated tests', $message);
        $this->assertStringContainsString(
            'PROTECTED:   cosmiclink_phase6i_acceptance (Phase 6I hardware-acceptance fixtures)',
            $message
        );
        $this->assertStringContainsString('migrate:fresh', $message);
    }

    public function test_guard_refuses_a_database_that_is_not_recognised(): void
    {
        $message = $this->guardRefusalFor(['database' => 'cosmiclink_stage']);

        $this->assertStringContainsString('target:      cosmiclink_stage', $message);
        $this->assertStringNotContainsString('PROTECTED', $message);
    }

    public function test_guard_refuses_a_connection_without_a_database_name(): void
    {
        $message = $this->guardRefusalFor(['database' => '']);

        $this->assertStringContainsString('target:      (empty)', $message);
        $this->assertStringContainsString('ambient .env value', $message);
    }

    public function test_guard_refuses_a_connection_url_that_hides_the_target(): void
    {
        $message = $this->guardRefusalFor([
            'database' => TestDatabaseGuard::APPROVED_DATABASE,
            'url' => 'pgsql://cosmiclink_app@127.0.0.1:5432/cosmiclink_phase6i_acceptance',
        ]);

        $this->assertStringContainsString('connection url is configured', $message);
        $this->assertStringContainsString('hides the real target', $message);
    }

    public function test_guard_refuses_an_unknown_default_connection(): void
    {
        $before = $this->app['config']->get('database.default');
        $this->app['config']->set('database.default', 'pgsq');

        try {
            $message = $this->guardRefusal();
        } finally {
            $this->app['config']->set('database.default', $before);
        }

        $this->assertStringContainsString('no database name is configured for [pgsq]', $message);
    }

    public function test_guard_refuses_an_empty_default_connection(): void
    {
        $before = $this->app['config']->get('database.default');
        $this->app['config']->set('database.default', '');

        try {
            $message = $this->guardRefusal();
        } finally {
            $this->app['config']->set('database.default', $before);
        }

        $this->assertStringContainsString('no default database connection is configured', $message);
        $this->assertStringContainsString('connection:  (unset)', $message);
    }

    public function test_guard_refuses_an_unreachable_target_instead_of_guessing(): void
    {
        // Port 1 is closed, so the server can never confirm the attachment.
        $message = $this->guardRefusalFor(['port' => 1]);

        $this->assertStringContainsString('refusing to run automated tests', $message);
        $this->assertStringContainsString('could not be verified against the server', $message);
        $this->assertStringContainsString('never run against an unverifiable target', $message);
    }

    public function test_guard_trusts_the_server_over_the_configuration(): void
    {
        $this->assertStringContainsString(
            'PROTECTED:   cosmiclink_phase6i_acceptance',
            $this->refusalForLiveDatabase('cosmiclink_phase6i_acceptance')
        );
    }

    private function refusalForLiveDatabase(string $database): string
    {
        // Never attach an automated test to a protected database.
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn($database);
        $probe = $this->createMock(PDO::class);
        $probe->expects($this->once())->method('query')
            ->with('select current_database()')->willReturn($statement);

        $name = 'guard_divergent';
        $this->app['db']->extend($name, fn (array $config) => new Connection(
            $config['probe_pdo'],
            $config['database'],
            $config['prefix'],
            $config
        ));
        $this->app['config']->set("database.connections.{$name}", [
            'driver' => $name,
            'url' => null,
            'database' => TestDatabaseGuard::APPROVED_DATABASE,
            'prefix' => '',
            'probe_pdo' => $probe,
        ]);

        $before = $this->app['config']->get('database.default');
        $this->app['config']->set('database.default', $name);

        try {
            $message = $this->guardRefusal();
        } finally {
            $this->app['config']->set('database.default', $before);
            $this->app['db']->purge($name);
        }

        return $message;
    }

    public function test_protected_databases_cannot_be_allowlisted(): void
    {
        $before = getenv('TEST_DATABASE_GUARD_APPROVED');
        try {
            foreach (TestDatabaseGuard::PROTECTED_DATABASES as $database) {
                putenv('TEST_DATABASE_GUARD_APPROVED=cosmiclink_test,'.$database);
                // No connection can be opened even if the configured name is protected.
                $message = $this->guardRefusalFor(['database' => $database, 'port' => 1]);
                $this->assertStringContainsString('PROTECTED:   '.$database, $message);
                $this->assertStringContainsString('PROTECTED:   '.$database, $this->refusalForLiveDatabase($database));
            }
        } finally {
            putenv($before === false ? 'TEST_DATABASE_GUARD_APPROVED' : 'TEST_DATABASE_GUARD_APPROVED='.$before);
        }
    }

    public function test_guard_refuses_a_different_allowlisted_live_database(): void
    {
        $before = getenv('TEST_DATABASE_GUARD_APPROVED');
        putenv('TEST_DATABASE_GUARD_APPROVED=cosmiclink_test,cosmiclink_other_test');
        try {
            $this->assertStringContainsString(
                'different database than configured',
                $this->refusalForLiveDatabase('cosmiclink_other_test')
            );
        } finally {
            putenv($before === false ? 'TEST_DATABASE_GUARD_APPROVED' : 'TEST_DATABASE_GUARD_APPROVED='.$before);
        }
    }

    public function test_sqlite_memory_cannot_hide_a_connection_url(): void
    {
        $this->assertStringContainsString('connection url is configured', $this->guardRefusalFor([
            'driver' => 'sqlite', 'database' => ':memory:', 'url' => 'sqlite:///protected.sqlite',
        ]));
    }

    public function test_guard_allows_an_in_memory_sqlite_connection(): void
    {
        $this->app['config']->set('database.connections.guard_sqlite', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $before = $this->app['config']->get('database.default');
        $this->app['config']->set('database.default', 'guard_sqlite');

        try {
            TestDatabaseGuard::assertIsolatedForTests($this->app);
            $this->addToAssertionCount(1);
        } finally {
            $this->app['config']->set('database.default', $before);
        }
    }

    public function test_ambient_database_variables_cannot_redirect_the_suite(): void
    {
        foreach (['GPCS', 'EGPCS'] as $variablesOrder) {
            $process = $this->runPhpunit(
                [
                    '--filter', 'test_the_run_is_actually_attached_to_the_approved_database',
                    'tests/Feature/Phase6ITestDatabaseIsolationTest.php',
                ],
                [
                    'DB_DATABASE' => 'cosmiclink',
                    'DB_URL' => 'pgsql://cosmiclink_app@127.0.0.1:5432/cosmiclink_phase6i_acceptance',
                    'TEST_DATABASE_GUARD_APPROVED' => false,
                    'APP_ENV' => 'production',
                ],
                $variablesOrder
            );

            $output = $process->getOutput().$process->getErrorOutput();

            $this->assertSame(0, $process->getExitCode(), "A pinned run must survive a poisoned shell:\n".$output);
            $this->assertStringNotContainsString('isolation guard', $output);
            $this->assertStringContainsString('OK', $output);
        }
    }

    public function test_a_run_without_the_pinned_configuration_is_refused_before_migrating(): void
    {
        // Explicit targets avoid depending on the developer's .env contents.
        foreach (TestDatabaseGuard::PROTECTED_DATABASES as $database) {
            $process = $this->runPhpunit(
                ['--no-configuration', '--bootstrap', 'vendor/autoload.php', 'tests/Feature/ExampleTest.php'],
                ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '', 'TEST_DATABASE_GUARD_APPROVED' => false]
            );

            $output = $process->getOutput().$process->getErrorOutput();

            $this->assertNotSame(0, $process->getExitCode(), "An unpinned run must not be allowed:\n".$output);
            $this->assertStringContainsString('Phase 6I test isolation guard', $output);
            $this->assertStringContainsString('PROTECTED:   '.$database, $output);
        }
    }

    /**
     * Poison the pgsql connection configuration, run the guard, and hand back
     * the refusal it produced.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function guardRefusalFor(array $overrides, string $connection = 'pgsql'): string
    {
        $defaultBefore = $this->app['config']->get('database.default');
        $connectionBefore = $this->app['config']->get("database.connections.{$connection}");

        $this->app['config']->set(
            "database.connections.{$connection}",
            array_merge(is_array($connectionBefore) ? $connectionBefore : [], $overrides)
        );
        $this->app['config']->set('database.default', $connection);
        $this->app['db']->purge($connection);

        try {
            return $this->guardRefusal();
        } finally {
            $this->app['config']->set('database.default', $defaultBefore);
            $this->app['config']->set("database.connections.{$connection}", $connectionBefore);
            $this->app['db']->purge($connection);
        }
    }

    /**
     * Assert the guard refuses the application as currently configured.
     */
    private function guardRefusal(): string
    {
        $message = null;

        try {
            TestDatabaseGuard::assertIsolatedForTests($this->app);
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        $this->assertNotNull(
            $message,
            'TestDatabaseGuard allowed a target it must refuse; approved = '
            .implode(', ', TestDatabaseGuard::approvedDatabases())
        );

        return $message;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    private function runPhpunit(array $arguments, array $environment, string $variablesOrder = 'GPCS'): Process
    {
        $process = new Process(
            array_merge([PHP_BINARY, '-d', 'variables_order='.$variablesOrder, 'vendor/bin/phpunit'], $arguments),
            dirname(__DIR__, 2),
            $environment,
            null,
            300
        );

        $process->run();

        return $process;
    }
}
