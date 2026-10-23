<?php

namespace App\Reporting;

interface ReportDefinition
{
    public function code(): string;

    /**
     * @return array<string, string> Column key => label.
     */
    public function columns(): array;

    /**
     * @return list<string>
     */
    public function scopes(): array;

    /**
     * @return array<string, string|null> Scope => required permission.
     */
    public function scopePermissions(): array;

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $userIds  Null means every user in scope.
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float|null>}
     */
    public function build(array $filters, ?array $userIds): array;
}
