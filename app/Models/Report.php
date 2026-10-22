<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    protected $fillable = ['code', 'name', 'scope', 'parameters', 'active'];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'active' => 'boolean'];
    }

    public function exports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }
}
