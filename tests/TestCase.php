<?php

namespace Tests;

use Database\Seeders\RbacSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUpTraits()
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database !== ':memory:' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against {$database}.");
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    protected function freshRequest(): static
    {
        Auth::forgetGuards();

        return $this;
    }
}
