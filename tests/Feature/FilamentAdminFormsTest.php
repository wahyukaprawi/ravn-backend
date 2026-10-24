<?php

namespace Tests\Feature;

use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\EmployeeAssignments\Pages\CreateEmployeeAssignment;
use App\Filament\Resources\EmployeeAssignments\Pages\EditEmployeeAssignment;
use App\Filament\Resources\EmployeeEmployments\Pages\CreateEmployeeEmployment;
use App\Filament\Resources\EmployeeEmployments\Pages\EditEmployeeEmployment;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\LeavePolicies\Pages\CreateLeavePolicy;
use App\Filament\Resources\LeavePolicies\Pages\EditLeavePolicy;
use App\Filament\Resources\LeaveTypes\Pages\CreateLeaveType;
use App\Filament\Resources\LeaveTypes\Pages\EditLeaveType;
use App\Filament\Resources\Positions\Pages\CreatePosition;
use App\Filament\Resources\Positions\Pages\EditPosition;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Schedules\Pages\CreateSchedule;
use App\Filament\Resources\Schedules\Pages\EditSchedule;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\WorkCalendars\Pages\CreateWorkCalendar;
use App\Filament\Resources\WorkCalendars\Pages\EditWorkCalendar;
use App\Filament\Resources\WorkLocations\Pages\CreateWorkLocation;
use App\Filament\Resources\WorkLocations\Pages\EditWorkLocation;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkLocation;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentAdminFormsTest extends TestCase
{
    public function test_super_admin_can_render_all_create_forms(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([
            CreateAnnouncement::class,
            CreateDepartment::class,
            CreateEmployee::class,
            CreateEmployeeAssignment::class,
            CreateEmployeeEmployment::class,
            CreateLeavePolicy::class,
            CreateLeaveType::class,
            CreatePosition::class,
            CreateSchedule::class,
            CreateUser::class,
            CreateWorkCalendar::class,
            CreateWorkLocation::class,
        ] as $page) {
            Livewire::test($page)->assertStatus(200);
        }
    }

    public function test_super_admin_can_render_all_edit_forms(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::query()->where('email', 'superadmin@ravn.test')->firstOrFail();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $records = [
            [EditAnnouncement::class, Announcement::query()->firstOrFail()],
            [EditDepartment::class, Department::query()->firstOrFail()],
            [EditEmployee::class, Employee::query()->firstOrFail()],
            [EditEmployeeAssignment::class, EmployeeAssignment::query()->firstOrFail()],
            [EditEmployeeEmployment::class, EmployeeEmployment::query()->firstOrFail()],
            [EditLeavePolicy::class, LeavePolicy::query()->firstOrFail()],
            [EditLeaveType::class, LeaveType::query()->firstOrFail()],
            [EditPosition::class, Position::query()->firstOrFail()],
            [EditRole::class, Role::query()->where('name', 'hr_admin')->firstOrFail()],
            [EditSchedule::class, Schedule::query()->firstOrFail()],
            [EditUser::class, User::query()->where('email', 'employee@ravn.test')->firstOrFail()],
            [EditWorkCalendar::class, WorkCalendar::query()->firstOrFail()],
            [EditWorkLocation::class, WorkLocation::query()->firstOrFail()],
        ];

        foreach ($records as [$page, $record]) {
            Livewire::test($page, ['record' => $record->getRouteKey()])->assertStatus(200);
        }
    }
}
