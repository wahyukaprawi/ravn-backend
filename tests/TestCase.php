<?php

namespace Tests;

use Database\Seeders\RbacSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

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
