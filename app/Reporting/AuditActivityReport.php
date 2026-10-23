<?php

namespace App\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AuditActivityReport implements ReportDefinition
{
    public function code(): string
    {
        return 'audit.activity';
    }

    public function columns(): array
    {
        return [
            'created_at' => 'Timestamp (UTC)',
            'actor_name' => 'Actor',
            'actor_email' => 'Actor email',
            'action' => 'Action',
            'subject_type' => 'Subject type',
            'subject_id' => 'Subject id',
            'outcome' => 'Outcome',
            'request_id' => 'Request id',
        ];
    }

    public function scopes(): array
    {
        return ['all'];
    }

    public function scopePermissions(): array
    {
        return [];
    }

    public function build(array $filters, ?array $userIds): array
    {
        $query = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->whereDate('audit_logs.created_at', '>=', $filters['from'])
            ->whereDate('audit_logs.created_at', '<=', $filters['to']);

        if ($userIds !== null) {
            $query->whereIn('audit_logs.actor_id', $userIds);
        }

        $rows = $query
            ->orderByDesc('audit_logs.id')
            ->select([
                'audit_logs.created_at',
                'audit_logs.action',
                'audit_logs.subject_type',
                'audit_logs.subject_id',
                'audit_logs.outcome',
                'audit_logs.request_id',
                'users.name as actor_name',
                'users.email as actor_email',
            ])
            ->limit((int) config('reports.max_rows') + 1)
            ->get()
            ->map(fn ($row): array => [
                'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
                'actor_name' => $row->actor_name,
                'actor_email' => $row->actor_email,
                'action' => $row->action,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id === null ? null : (int) $row->subject_id,
                'outcome' => $row->outcome,
                'request_id' => $row->request_id,
            ])
            ->all();

        return [
            'rows' => $rows,
            'totals' => ['entries' => count($rows)],
        ];
    }
}
