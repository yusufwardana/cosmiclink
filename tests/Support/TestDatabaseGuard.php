<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Throwable;

/**
 * Fail-closed guard that keeps automated tests away from real databases.
 *
 * Phase 6I keeps three PostgreSQL databases side by side on the same cluster:
 *   - cosmiclink                       development data (never disposable)
 *   - cosmiclink_phase6i_acceptance    hardware-acceptance fixtures (expensive)
 *   - cosmiclink_test                  the only database automated tests may wipe
 *
 * Illuminate's RefreshDatabase runs `migrate:fresh` once per test process, which
 * drops every table of whatever the default connection points at. `phpunit.xml`
 * used to set DB_DATABASE without force="true", so a DB_DATABASE or DB_URL
 * exported in the developer shell won and the acceptance database was reset.
 *
 * This guard makes that class of mistake impossible to repeat: the *live*
 * database name is read back from the server on every test application boot -
 * before RefreshDatabase can run - and the boot is refused unless that name is
 * on the explicit allow-list. Anything unknown, including the protected names,
 * fails closed.
 */
final class TestDatabaseGuard
{
    /**
     * The single database automated tests are allowed to destroy.
     */
    public const APPROVED_DATABASE = 'cosmiclink_test';

    /**
     * Databases an automated test run must never touch.
     *
     * Listed so a refusal can name the damage it prevented. The guard is an
     * allow-list, so an unlisted name is refused either way.
     */
    public const PROTECTED_DATABASES = [
        'cosmiclink',
        'cosmiclink_phase6i_acceptance',
    ];

    private static int $verifications = 0;

    /**
     * Databases automated tests may be pointed at.
     *
     * @return list<string>
     */
    public static function approvedDatabases(): array
    {
        $configured = getenv('TEST_DATABASE_GUARD_APPROVED');

        if (! is_string($configured) || trim($configured) === '') {
            return [self::APPROVED_DATABASE];
        }

        $names = array_map(
            static fn (string $name): string => trim($name),
            explode(',', $configured)
        );

        $names = array_values(array_unique(array_filter(
            $names,
            static fn (string $name): bool => $name !== ''
        )));

        return $names === [] ? [self::APPROVED_DATABASE] : $names;
    }

    /**
     * Verify a freshly booted test application is attached to an approved database.
     *
     * Called from Tests\TestCase::createApplication(), i.e. after the framework
     * has bootstrapped but before any testing trait (RefreshDatabase included)
     * has had a chance to migrate or truncate anything.
     *
     * @throws RuntimeException when the target database is not approved
     */
    public static function assertIsolatedForTests(Application $app): void
    {
        $approved = self::approvedDatabases();

        $connection = trim((string) $app['config']->get('database.default'));

        if ($connection === '') {
            self::refuse($connection, '(unset)', $approved, 'no default database connection is configured');
        }

        $path = "database.connections.{$connection}";
        $driver = trim((string) $app['config']->get("{$path}.driver"));
        $configured = trim((string) $app['config']->get("{$path}.database"));
        $url = trim((string) $app['config']->get("{$path}.url"));

        if ($url !== '') {
            self::refuse(
                $connection,
                $configured === '' ? '(empty)' : $configured,
                $approved,
                "a connection url is configured for [{$connection}], which replaces the database name and hides the real target"
            );
        }

        // Only a URL-free in-memory connection qualifies for this exception.
        if ($driver === 'sqlite' && $configured === ':memory:') {
            self::$verifications++;

            return;
        }

        if ($configured === '') {
            self::refuse(
                $connection,
                '(empty)',
                $approved,
                "no database name is configured for [{$connection}], so the driver would fall back to the ambient .env value"
            );
        }

        if (in_array($configured, self::PROTECTED_DATABASES, true) || ! in_array($configured, $approved, true)) {
            self::refuse(
                $connection,
                $configured,
                $approved,
                "the configured database for [{$connection}] is not an approved automated-test database"
            );
        }

        $live = self::liveDatabaseName($app, $connection);

        if ($live !== $configured || in_array($live, self::PROTECTED_DATABASES, true) || ! in_array($live, $approved, true)) {
            self::refuse(
                $connection,
                $live === '' ? '(empty)' : $live,
                $approved,
                "the server reports [{$connection}] is attached to a different database than configured ({$configured})"
            );
        }

        self::$verifications++;
    }

    /**
     * How many boots the guard has cleared in this process.
     */
    public static function verificationCount(): int
    {
        return self::$verifications;
    }

    /**
     * Ask the server which database this connection is actually attached to.
     *
     * Deliberately a direct query rather than Connection::getDatabaseName(),
     * which only echoes configuration and would not notice a DSN override or a
     * connection object that was built before the configuration changed.
     */
    private static function liveDatabaseName(Application $app, string $connection): string
    {
        try {
            $name = $app['db']->connection($connection)->getPdo()
                ->query('select current_database()')
                ->fetchColumn();
        } catch (Throwable $exception) {
            // Fail closed: without proof of the target there is no safe
            // database to run destructive migrations against.
            throw new RuntimeException(
                "Phase 6I test isolation guard: refusing to run automated tests - the [{$connection}] "
                .'connection could not be verified against the server. '
                ."({$exception->getMessage()}) Start PostgreSQL and retry; destructive test "
                .'migrations are never run against an unverifiable target.',
                previous: $exception
            );
        }

        return is_string($name) ? trim($name) : '';
    }

    /**
     * Abort the boot with an explanation of what was prevented.
     *
     * @param  list<string>  $approved
     *
     * @throws RuntimeException
     */
    private static function refuse(string $connection, string $observed, array $approved, string $reason): never
    {
        $lines = [
            'Phase 6I test isolation guard: refusing to run automated tests.',
            "  reason:      {$reason}",
            '  connection:  '.($connection === '' ? '(unset)' : $connection),
            '  target:      '.$observed,
            '  approved:    '.implode(', ', $approved),
        ];

        if (in_array($observed, self::PROTECTED_DATABASES, true)) {
            $lines[] = '  PROTECTED:   '.$observed.' ('.self::purposeOf($observed).')';
        }

        $lines[] = '  RefreshDatabase runs `migrate:fresh`, which would drop and rebuild every table in the target database.';
        $lines[] = '  fix: clear DB_DATABASE/DB_URL from your shell and run `php artisan test` or `vendor/bin/phpunit`; '
            .'phpunit.xml pins DB_DATABASE='.self::APPROVED_DATABASE.' with forced env and matching server entries.';

        throw new RuntimeException(implode("\n", $lines));
    }

    private static function purposeOf(string $database): string
    {
        return match ($database) {
            'cosmiclink' => 'development data',
            'cosmiclink_phase6i_acceptance' => 'Phase 6I hardware-acceptance fixtures',
            default => 'not an automated-test database',
        };
    }
}
