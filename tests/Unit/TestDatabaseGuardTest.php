<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\CreatesApplication;

/**
 * The suite runs RefreshDatabase, which drops every table. This guard is the
 * thing standing between a misconfigured phpunit.xml and the development
 * database, so its behaviour is pinned here.
 */
class TestDatabaseGuardTest extends TestCase
{
    private function guard(): object
    {
        return new class {
            use CreatesApplication;

            public function check(string $database): void
            {
                $container = new Container();
                $container->instance('config', new Repository([
                    'database' => [
                        'default' => 'mysql',
                        'connections' => ['mysql' => ['database' => $database]],
                    ],
                ]));

                $this->guardAgainstNonTestDatabase($container);
            }
        };
    }

    /** @return array<string, array{string}> */
    public static function provideTestDatabaseNames(): array
    {
        return [
            'suffixed testing' => ['post_manage_testing'],
            'suffixed test' => ['post_manage_test'],
            'prefixed' => ['test_post_manage'],
            'in-memory sqlite' => [':memory:'],
            'sqlite file path' => ['/tmp/testing.sqlite'],
        ];
    }

    /** @dataProvider provideTestDatabaseNames */
    public function test_it_allows_test_databases(string $database): void
    {
        $this->guard()->check($database);

        $this->assertTrue(true, 'Guard permitted the test database.');
    }

    /** @return array<string, array{string}> */
    public static function provideProtectedDatabaseNames(): array
    {
        return [
            'the dev database' => ['post_manage'],
            'a production-looking name' => ['pos_management'],
            'an april snapshot' => ['post_manage_v20260421_170834'],
        ];
    }

    /** @dataProvider provideProtectedDatabaseNames */
    public function test_it_refuses_non_test_databases(string $database): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run tests against database');

        $this->guard()->check($database);
    }

    public function test_the_refusal_names_the_offending_database(): void
    {
        try {
            $this->guard()->check('post_manage');
            $this->fail('Guard should have refused the development database.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('post_manage', $e->getMessage());
            $this->assertStringContainsString('RefreshDatabase', $e->getMessage());
        }
    }
}
