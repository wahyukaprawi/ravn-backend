<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\AnnouncementRead;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnouncementService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(User $actor, array $data): Announcement
    {
        $audiences = $this->normalizeAudiences($data['audiences'] ?? []);
        $scheduledAt = $this->scheduledAt($data['scheduled_at'] ?? null);

        return DB::transaction(function () use ($actor, $data, $audiences, $scheduledAt) {
            $announcement = Announcement::query()->create([
                'title' => $data['title'],
                'body' => $data['body'],
                'status' => $scheduledAt ? Announcement::STATUS_SCHEDULED : Announcement::STATUS_DRAFT,
                'author_id' => $actor->getKey(),
                'scheduled_at' => $scheduledAt,
            ]);

            $this->replaceAudiences($announcement, $audiences);
            $this->audit->record(
                'announcement.created',
                $announcement,
                null,
                $this->auditPayload($announcement->fresh('audiences')),
                $actor,
            );

            return $announcement->fresh('audiences');
        });
    }

    public function update(Announcement $announcement, User $actor, array $data): Announcement
    {
        $audiences = $this->normalizeAudiences($data['audiences'] ?? []);
        $scheduledAt = $this->scheduledAt($data['scheduled_at'] ?? null);

        return DB::transaction(function () use ($announcement, $actor, $data, $audiences, $scheduledAt) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->getKey());

            if (! in_array($locked->status, [Announcement::STATUS_DRAFT, Announcement::STATUS_SCHEDULED], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft or scheduled announcements can be edited.',
                ]);
            }

            $before = $this->auditPayload($locked->load('audiences'));

            $locked->forceFill([
                'title' => $data['title'],
                'body' => $data['body'],
                'status' => $scheduledAt ? Announcement::STATUS_SCHEDULED : Announcement::STATUS_DRAFT,
                'scheduled_at' => $scheduledAt,
            ])->save();

            $this->replaceAudiences($locked, $audiences);
            $this->audit->record(
                'announcement.updated',
                $locked,
                $before,
                $this->auditPayload($locked->fresh('audiences')),
                $actor,
            );

            return $locked->fresh('audiences');
        });
    }

    public function publish(Announcement $announcement, ?User $actor = null): Announcement
    {
        return DB::transaction(function () use ($announcement, $actor) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->getKey());

            if ($locked->status === Announcement::STATUS_PUBLISHED) {
                return $locked->load('audiences');
            }

            if (! in_array($locked->status, [Announcement::STATUS_DRAFT, Announcement::STATUS_SCHEDULED], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft or scheduled announcements can be published.',
                ]);
            }

            $before = $this->auditPayload($locked->load('audiences'));

            $locked->forceFill([
                'status' => Announcement::STATUS_PUBLISHED,
                'published_at' => now(),
                'archived_at' => null,
            ])->save();

            $this->audit->record(
                'announcement.published',
                $locked,
                $before,
                $this->auditPayload($locked->fresh('audiences')),
                $actor,
            );

            OutboxMessage::query()->create([
                'event_type' => 'announcement.published',
                'aggregate_type' => $locked->getMorphClass(),
                'aggregate_id' => $locked->getKey(),
                'payload' => ['announcement_id' => $locked->getKey()],
                'status' => OutboxMessage::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
            ]);

            return $locked->fresh('audiences');
        });
    }

    public function archive(Announcement $announcement, User $actor): Announcement
    {
        return DB::transaction(function () use ($announcement, $actor) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->getKey());

            if ($locked->status === Announcement::STATUS_ARCHIVED) {
                return $locked->load('audiences');
            }

            if ($locked->status !== Announcement::STATUS_PUBLISHED) {
                throw ValidationException::withMessages([
                    'status' => 'Only published announcements can be archived.',
                ]);
            }

            $before = $this->auditPayload($locked->load('audiences'));

            $locked->forceFill([
                'status' => Announcement::STATUS_ARCHIVED,
                'archived_at' => now(),
            ])->save();

            $this->audit->record(
                'announcement.archived',
                $locked,
                $before,
                $this->auditPayload($locked->fresh('audiences')),
                $actor,
            );

            return $locked->fresh('audiences');
        });
    }

    public function markRead(Announcement $announcement, User $user): AnnouncementRead
    {
        $employee = $user->employee;

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee' => 'An employee profile is required to read announcements.',
            ]);
        }

        return DB::transaction(function () use ($announcement, $user, $employee) {
            return AnnouncementRead::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement->getKey(),
                    'employee_id' => $employee->getKey(),
                ],
                [
                    'user_id' => $user->getKey(),
                    'read_at' => now(),
                ],
            );
        });
    }

    public function canView(Announcement $announcement, User $user): bool
    {
        if ($announcement->status !== Announcement::STATUS_PUBLISHED) {
            return false;
        }

        return $this->visibleQuery($user)
            ->whereKey($announcement->getKey())
            ->exists();
    }

    public function visibleQuery(User $user): Builder
    {
        $query = Announcement::query()->where('status', Announcement::STATUS_PUBLISHED);
        $employee = $user->employee;

        if (! $employee) {
            return $query->whereRaw('1 = 0');
        }

        $assignment = $employee->currentAssignment;
        $departmentId = $assignment?->department_id;
        $locationId = $assignment?->work_location_id;

        return $query->where(function (Builder $query) use ($employee, $departmentId, $locationId) {
            $query->whereHas('audiences', function (Builder $query) {
                $query->where('audience_type', AnnouncementAudience::TYPE_ALL_EMPLOYEES)
                    ->where('audience_id', 0);
            });

            $query->orWhereHas('audiences', function (Builder $query) use ($employee) {
                $query->where('audience_type', AnnouncementAudience::TYPE_SPECIFIC_EMPLOYEE)
                    ->where('audience_id', $employee->getKey());
            });

            if ($departmentId !== null) {
                $query->orWhereHas('audiences', function (Builder $query) use ($departmentId) {
                    $query->where('audience_type', AnnouncementAudience::TYPE_DEPARTMENT)
                        ->where('audience_id', $departmentId);
                });
            }

            if ($locationId !== null) {
                $query->orWhereHas('audiences', function (Builder $query) use ($locationId) {
                    $query->where('audience_type', AnnouncementAudience::TYPE_WORK_LOCATION)
                        ->where('audience_id', $locationId);
                });
            }
        });
    }

    public function recipients(Announcement $announcement): Collection
    {
        $audiences = $announcement->audiences()->get();

        if ($audiences->isEmpty()) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        if ($audiences->contains('audience_type', AnnouncementAudience::TYPE_ALL_EMPLOYEES)) {
            return User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->whereHas('employee')
                ->orderBy('id')
                ->get();
        }

        $specificEmployees = $this->audienceIds($audiences, AnnouncementAudience::TYPE_SPECIFIC_EMPLOYEE);
        $departmentIds = $this->audienceIds($audiences, AnnouncementAudience::TYPE_DEPARTMENT);
        $locationIds = $this->audienceIds($audiences, AnnouncementAudience::TYPE_WORK_LOCATION);

        $query = User::query()->where('status', User::STATUS_ACTIVE);

        $query->where(function (Builder $query) use ($specificEmployees, $departmentIds, $locationIds) {
            $hasCondition = false;

            if ($specificEmployees !== []) {
                $query->whereHas('employee', function (Builder $query) use ($specificEmployees) {
                    $query->whereIn('id', $specificEmployees);
                });
                $hasCondition = true;
            }

            if ($departmentIds !== []) {
                $method = $hasCondition ? 'orWhereHas' : 'whereHas';
                $query->{$method}('employee.assignments', function (Builder $query) use ($departmentIds) {
                    $this->applyCurrentAssignment($query);
                    $query->whereIn('department_id', $departmentIds);
                });
                $hasCondition = true;
            }

            if ($locationIds !== []) {
                $method = $hasCondition ? 'orWhereHas' : 'whereHas';
                $query->{$method}('employee.assignments', function (Builder $query) use ($locationIds) {
                    $this->applyCurrentAssignment($query);
                    $query->whereIn('work_location_id', $locationIds);
                });
                $hasCondition = true;
            }

            if (! $hasCondition) {
                $query->whereRaw('1 = 0');
            }
        });

        return $query->orderBy('id')->get();
    }

    public function analytics(Announcement $announcement): array
    {
        $recipientCount = $this->recipients($announcement)->count();
        $readCount = $announcement->reads()->count();

        return [
            'recipient_count' => $recipientCount,
            'read_count' => $readCount,
            'read_rate' => $recipientCount > 0 ? round($readCount / $recipientCount, 4) : 0.0,
        ];
    }

    private function normalizeAudiences(array $audiences): array
    {
        $normalized = [];

        foreach ($audiences as $index => $audience) {
            $type = is_array($audience) ? ($audience['type'] ?? null) : null;
            $audienceId = is_array($audience) ? ($audience['id'] ?? null) : null;

            if (! is_string($type) || ! in_array($type, AnnouncementAudience::TYPES, true)) {
                throw ValidationException::withMessages([
                    "audiences.{$index}.type" => 'The selected audience type is invalid.',
                ]);
            }

            if ($type === AnnouncementAudience::TYPE_ALL_EMPLOYEES) {
                $audienceId = 0;
            } else {
                if (! is_numeric($audienceId) || (int) $audienceId < 1) {
                    throw ValidationException::withMessages([
                        "audiences.{$index}.id" => 'The audience target is required.',
                    ]);
                }

                $audienceId = (int) $audienceId;
                $this->assertAudienceTargetExists($type, $audienceId, $index);
            }

            $normalized[$type.':'.$audienceId] = [
                'audience_type' => $type,
                'audience_id' => $audienceId,
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'audiences' => 'At least one audience is required.',
            ]);
        }

        return array_values($normalized);
    }

    private function assertAudienceTargetExists(string $type, int $audienceId, int $index): void
    {
        $exists = match ($type) {
            AnnouncementAudience::TYPE_DEPARTMENT => Department::query()->whereKey($audienceId)->exists(),
            AnnouncementAudience::TYPE_WORK_LOCATION => WorkLocation::query()
                ->whereKey($audienceId)
                ->where('active', true)
                ->exists(),
            AnnouncementAudience::TYPE_SPECIFIC_EMPLOYEE => Employee::query()->whereKey($audienceId)->exists(),
            default => false,
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                "audiences.{$index}.id" => 'The selected audience target is invalid.',
            ]);
        }
    }

    private function scheduledAt(mixed $scheduledAt): ?Carbon
    {
        if ($scheduledAt === null || $scheduledAt === '') {
            return null;
        }

        $date = $scheduledAt instanceof Carbon ? $scheduledAt : Carbon::parse((string) $scheduledAt);

        if ($date->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'The scheduled time must be in the future.',
            ]);
        }

        return $date;
    }

    private function replaceAudiences(Announcement $announcement, array $audiences): void
    {
        $announcement->audiences()->delete();

        foreach ($audiences as $audience) {
            $announcement->audiences()->create($audience);
        }
    }

    private function audienceIds(Collection $audiences, string $type): array
    {
        return $audiences
            ->where('audience_type', $type)
            ->pluck('audience_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function applyCurrentAssignment(Builder $query): void
    {
        $today = now()->toDateString();

        $query->whereNull('canceled_at')
            ->whereDate('effective_from', '<=', $today)
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today);
            });
    }

    private function auditPayload(Announcement $announcement): array
    {
        return [
            'title' => $announcement->title,
            'status' => $announcement->status,
            'scheduled_at' => $announcement->scheduled_at?->toIso8601String(),
            'published_at' => $announcement->published_at?->toIso8601String(),
            'archived_at' => $announcement->archived_at?->toIso8601String(),
            'audience_count' => $announcement->audiences->count(),
            'audiences' => $announcement->audiences
                ->map(fn (AnnouncementAudience $audience) => [
                    'type' => $audience->audience_type,
                    'id' => $audience->audience_id,
                ])
                ->all(),
        ];
    }
}
