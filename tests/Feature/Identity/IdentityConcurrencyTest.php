<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Services\UserAdministrationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class IdentityConcurrencyTest extends TestCase
{
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('IAM concurrency evidence requires MySQL 8.4 and pcntl.');
        }

        $this->artisan('migrate:fresh');
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->artisan('migrate:fresh');
        }

        parent::tearDown();
    }

    public function test_iam_t09_two_concurrent_super_admin_removals_leave_one_active_super_admin(): void
    {
        $first = User::factory()->create(['email' => 'first.super@example.test'])->assignRole('super_admin');
        $second = User::factory()->create(['email' => 'second.super@example.test'])->assignRole('super_admin');

        $results = $this->runConcurrently([
            fn () => app(UserAdministrationService::class)->deactivate($first, $second)->status,
            fn () => app(UserAdministrationService::class)->deactivate($second, $first)->status,
        ]);

        $this->assertSame(1, $this->countStatus($results, 'success'));
        $this->assertSame(1, $this->countStatus($results, 'validation'));
        $this->assertSame(
            1,
            User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
                ->count(),
        );
    }

    public function test_iam_t10_two_concurrent_invites_with_the_same_normalized_email_create_one_account(): void
    {
        $actor = User::factory()->create(['email' => 'inviter@example.test'])->assignRole('super_admin');

        $results = $this->runConcurrently([
            fn () => app(UserAdministrationService::class)->invite($actor, [
                'name' => 'Race One',
                'email' => 'Race@Example.test',
            ])[0]->email,
            fn () => app(UserAdministrationService::class)->invite($actor, [
                'name' => 'Race Two',
                'email' => 'race@example.test',
            ])[0]->email,
        ]);

        $this->assertSame(1, $this->countStatus($results, 'success'));
        $this->assertSame(1, $this->countStatus($results, 'validation'));
        $this->assertSame(1, User::query()->where('email', 'race@example.test')->count());
    }

    protected function runConcurrently(array $callbacks): array
    {
        $goFile = tempnam(sys_get_temp_dir(), 'ravn-go-');
        @unlink($goFile);

        $readyFiles = [];
        $resultFiles = [];
        $pids = [];

        DB::purge();

        foreach ($callbacks as $index => $callback) {
            $readyFiles[$index] = tempnam(sys_get_temp_dir(), 'ravn-ready-');
            $resultFiles[$index] = tempnam(sys_get_temp_dir(), 'ravn-result-');

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork a concurrency worker.');
            }

            if ($pid === 0) {
                $result = $this->runWorker($callback, $readyFiles[$index], $goFile);

                file_put_contents($resultFiles[$index], json_encode($result));
                exit(0);
            }

            $pids[$index] = $pid;
        }

        DB::reconnect();

        foreach ($readyFiles as $readyFile) {
            $deadline = microtime(true) + 10;

            while (trim((string) file_get_contents($readyFile)) !== 'ready') {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('A concurrency worker did not become ready.');
                }

                usleep(10000);
            }
        }

        file_put_contents($goFile, 'go');

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        foreach ($resultFiles as $index => $resultFile) {
            $decoded = json_decode((string) file_get_contents($resultFile), true);
            $results[$index] = is_array($decoded) ? $decoded : ['status' => 'error', 'message' => 'No worker result.'];

            @unlink($resultFile);
        }

        foreach ($readyFiles as $readyFile) {
            @unlink($readyFile);
        }

        @unlink($goFile);

        return $results;
    }

    protected function runWorker(callable $callback, string $readyFile, string $goFile): array
    {
        try {
            DB::purge();
            DB::reconnect();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }

        file_put_contents($readyFile, 'ready');

        $deadline = microtime(true) + 10;

        while (! file_exists($goFile)) {
            if (microtime(true) > $deadline) {
                return ['status' => 'error', 'message' => 'The concurrency barrier timed out.'];
            }

            usleep(10000);
        }

        try {
            $callback();

            return ['status' => 'success'];
        } catch (ValidationException $exception) {
            return [
                'status' => 'validation',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ];
        }
    }

    protected function countStatus(array $results, string $status): int
    {
        return count(array_filter($results, fn (array $result) => $result['status'] === $status));
    }
}
