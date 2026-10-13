<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.connections.central.database') !== 'makerly_test') {
            $this->fail('Refusing to run tests against '.config('database.connections.central.database'));
        }

        $this->withoutVite();
    }
}
