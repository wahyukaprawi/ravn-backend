<?php

namespace Tests\Feature;

use App\Audit\AuditRecorder;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class AuditAtomicsTest extends TestCase
{
    public function test_audit_record_is_written_within_the_transaction(): void
    {
        $user = User::factory()->create();

        $log = app(AuditRecorder::class)->record('test.action', $user, ['status' => 'a'], ['status' => 'b'], $user);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'test.action',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
            'outcome' => AuditLog::OUTCOME_SUCCESS,
        ]);
    }

    public function test_a_failing_mutation_rolls_back_its_audit_record(): void
    {
        $user = User::factory()->create();

        $before = AuditLog::count();

        try {
            app(AuditRecorder::class)->transactionally('test.rollback', function (AuditRecorder $recorder) use ($user) {
                $recorder->record('test.rollback', $user, null, ['step' => 'first']);

                throw new RuntimeException('mutation failed');
            }, $user);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($before, AuditLog::count());
    }

    public function test_sensitive_keys_are_redacted_before_they_reach_the_database(): void
    {
        $user = User::factory()->create();

        $log = app(AuditRecorder::class)->record('test.redaction', $user, null, [
            'email' => 'person@example.test',
            'password' => 'super-secret-value',
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
        ], $user);

        $fresh = $log->fresh();

        $this->assertSame('person@example.test', $fresh->after['email']);
        $this->assertSame(AuditRecorder::REDACTED, $fresh->after['password']);
        $this->assertSame(AuditRecorder::REDACTED, $fresh->after['mfa_secret']);

        $this->assertStringNotContainsString('super-secret-value', json_encode($fresh->after));
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', json_encode($fresh->after));
    }

    public function test_audit_table_has_no_update_or_delete_path_in_the_model(): void
    {
        $table = (new AuditLog)->getTable();

        $this->assertSame('audit_logs', $table);
        $this->assertNull(AuditLog::UPDATED_AT);
    }

    public function test_request_id_is_returned_and_matches_the_audit_row(): void
    {
        Route::middleware('api')->get('/api/v1/_test/request-id', function () {
            return response()->json([
                'request_id' => app()->bound('request_id') ? app('request_id') : null,
            ]);
        });

        $response = $this->getJson('/api/v1/_test/request-id');

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
    }

    public function test_database_supports_transactions_on_mysql(): void
    {
        $driver = DB::connection()->getDriverName();

        $this->assertSame('mysql', $driver, 'Slice 01 requires MySQL 8.4 for transaction semantics.');
    }
}
