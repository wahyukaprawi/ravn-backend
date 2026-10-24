<?php

namespace Tests\Feature;

use App\Audit\AuditRecorder;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ActivationTokenService;
use App\Services\MfaService;
use App\Services\SessionService;
use App\Services\UserAdministrationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminAuditRollbackTest extends TestCase
{
    public function test_a_failing_audit_record_rolls_back_the_invitation(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $recorder = new class extends AuditRecorder
        {
            public function record(
                string $action,
                ?Model $subject = null,
                ?array $before = null,
                ?array $after = null,
                ?User $actor = null,
                string $outcome = 'success',
                ?string $requestId = null,
            ): AuditLog {
                throw new RuntimeException('audit write failed');
            }
        };

        $service = new UserAdministrationService(
            app(ActivationTokenService::class),
            app(SessionService::class),
            app(MfaService::class),
            $recorder,
        );

        $before = User::count();

        try {
            $service->invite($actor, ['name' => 'Rollback', 'email' => 'rollback@example.test']);
            $this->fail('Expected the audit failure to abort the transaction.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($before, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
    }

    public function test_each_admin_mutation_writes_exactly_one_audit_row(): void
    {
        $actor = User::factory()->superAdmin()->create([
            'password' => Hash::make('correct-password-123'),
        ]);
        $target = User::factory()->create();

        $service = app(UserAdministrationService::class);

        $countBefore = AuditLog::query()->where('action', 'user.update')->count();
        $service->update($actor, $target, ['name' => 'Renamed']);
        $countAfter = AuditLog::query()->where('action', 'user.update')->count();

        $this->assertSame($countBefore + 1, $countAfter);
    }
}
