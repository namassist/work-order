<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Refuses to boot RefreshDatabase and the other testing traits unless the
     * default connection points at a test database (`*_test`, or its
     * parallel copies `*_test_test_N`), so a missing or overridden
     * DB_DATABASE can never wipe the development database.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $database = (string) $this->app['db']->connection()->getDatabaseName();

        if (preg_match('/_test(_test_\d+)?$/', $database) !== 1) {
            throw new RuntimeException("Tests must run against a *_test database, not [{$database}]. Check DB_DATABASE in phpunit.xml.");
        }

        return parent::setUpTraits();
    }
}
