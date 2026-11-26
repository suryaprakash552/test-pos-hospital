<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->guardAgainstNonTestDatabase($app);

        return $app;
    }

    /**
     * Refuse to run when the configured database is not an obvious test database.
     *
     * The suite uses RefreshDatabase, which runs migrate:fresh and drops every
     * table. phpunit.xml once had its DB_* overrides commented out, so the suite
     * silently targeted the development database and wiped it. This runs before
     * setUpTraits(), so it aborts before RefreshDatabase can touch anything.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function guardAgainstNonTestDatabase($app): void
    {
        $config = $app->make('config');

        $connection = $config->get('database.default');
        $database = (string) $config->get("database.connections.{$connection}.database");

        if ($this->isTestDatabase($database)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against database "%s" on connection "%s". '
            .'The suite uses RefreshDatabase and would drop every table. '
            .'Point DB_DATABASE at a dedicated test database (a name containing '
            .'"test", e.g. post_manage_testing) - see phpunit.xml.',
            $database !== '' ? $database : '(empty)',
            $connection
        ));
    }

    /**
     * In-memory SQLite, or any database whose name marks it as a test database.
     */
    protected function isTestDatabase(string $database): bool
    {
        if ($database === ':memory:' || $database === '') {
            // An empty name only happens for drivers that do not use one
            // (in-memory sqlite); there is nothing persistent to destroy.
            return true;
        }

        return str_contains(strtolower(basename($database)), 'test');
    }
}
