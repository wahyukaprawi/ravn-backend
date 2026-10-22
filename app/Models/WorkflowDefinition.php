<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowDefinition extends Model
{
    protected $fillable = [
        'code',
        'name',
        'version',
        'schema',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }
}
