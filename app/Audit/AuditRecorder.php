<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use RuntimeException;

class AuditRecorder
{
    public const REDACTED = '[redacted]';

    public const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'remember_token',
        'secret',
        'mfa_secret',
        'recovery_codes',
        'code',
        'authorization',
    ];

    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
        string $outcome = AuditLog::OUTCOME_SUCCESS,
        ?string $requestId = null,
    ): AuditLog {
        $actor ??= Auth::user();

        $log = new AuditLog([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'request_id' => $requestId ?? $this->requestId(),
            'before' => $this->scrub($before),
            'after' => $this->scrub($after),
            'outcome' => $outcome,
            'created_at' => now(),
        ]);

        $log->save();

        return $log;
    }

    public function transactionally(string $action, callable $callback, ?Model $subject = null): mixed
    {
        return DB::transaction(function () use ($action, $callback, $subject) {
            $result = $callback($this);

            if ($result instanceof AuditLog) {
                return $result;
            }

            return $this->record($action, $subject);
        });
    }

    public function requestId(): string
    {
        $existing = Request::header('X-Request-Id');

        if (is_string($existing) && $existing !== '') {
            return mb_substr($existing, 0, 64);
        }

        return (string) str()->uuid();
    }

    public function scrub(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return $this->scrubArray($payload);
    }

    protected function scrubArray(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $payload[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->scrubArray($value);
            }
        }

        return $payload;
    }

    public static function guardNoSensitiveColumns(AuditLog $log): void
    {
        foreach ([$log->before ?? [], $log->after ?? []] as $payload) {
            foreach (array_keys($payload) as $key) {
                if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)
                    && $payload[$key] !== self::REDACTED) {
                    throw new RuntimeException("Sensitive key [{$key}] reached the audit log unredacted.");
                }
            }
        }
    }
}
