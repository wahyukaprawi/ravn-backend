<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiErrorEnvelopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/v1/_test/not-found', function () {
            abort(404);
        });

        Route::middleware('api')->get('/api/v1/_test/validation', function () {
            request()->validate(['email' => ['required', 'email']]);

            return response()->json(['ok' => true]);
        });

        Route::middleware('api')->get('/api/v1/_test/forbidden', function () {
            abort(403);
        });

        Route::middleware('api')->get('/api/v1/_test/teapot', function () {
            abort(418, 'I am a teapot.');
        });
    }

    public function test_not_found_uses_the_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/_test/not-found');

        $response->assertNotFound();
        $response->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);
        $this->assertSame('NOT_FOUND', $response->json('error.code'));
    }

    public function test_validation_failure_uses_the_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/_test/validation');

        $response->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $response->json('error.code'));
        $this->assertArrayHasKey('email', $response->json('error.details'));
    }

    public function test_forbidden_uses_the_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/_test/forbidden');

        $response->assertForbidden();
        $this->assertSame('FORBIDDEN', $response->json('error.code'));
    }

    public function test_unauthenticated_uses_the_error_envelope(): void
    {
        Route::middleware('api')->get('/api/v1/_test/protected', function () {
            return response()->json(['ok' => true]);
        })->middleware('auth:sanctum');

        $response = $this->getJson('/api/v1/_test/protected');

        $response->assertUnauthorized();
        $this->assertSame('UNAUTHENTICATED', $response->json('error.code'));
    }

    public function test_unexpected_statuses_still_use_the_envelope(): void
    {
        $response = $this->getJson('/api/v1/_test/teapot');

        $response->assertStatus(418);
        $response->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);
    }

    public function test_the_envelope_never_leaks_a_stack_trace_in_production_mode(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/not-found');

        $body = $response->getContent();

        $this->assertStringNotContainsString('vendor/laravel', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
    }
}
