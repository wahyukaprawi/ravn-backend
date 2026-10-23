<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementAudience extends Model
{
    public const TYPE_ALL_EMPLOYEES = 'all_employees';

    public const TYPE_DEPARTMENT = 'department';

    public const TYPE_WORK_LOCATION = 'work_location';

    public const TYPE_SPECIFIC_EMPLOYEE = 'specific_employee';

    public const TYPES = [
        self::TYPE_ALL_EMPLOYEES,
        self::TYPE_DEPARTMENT,
        self::TYPE_WORK_LOCATION,
        self::TYPE_SPECIFIC_EMPLOYEE,
    ];

    protected $fillable = ['announcement_id', 'audience_type', 'audience_id'];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
