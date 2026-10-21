<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        if (TestDatabaseGuard::mysqlIntegrationRequested()) {
            TestDatabaseGuard::assertMysqlIntegrationEnvironment();
        } else {
            TestDatabaseGuard::forceStandardEnvironment();
            TestDatabaseGuard::assertStandardEnvironment();
        }

        $app = parent::createApplication();
        TestDatabaseGuard::assertBootedConfiguration($app, TestDatabaseGuard::mysqlIntegrationRequested());

        return $app;
    }
}
