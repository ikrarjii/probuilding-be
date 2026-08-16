<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect([
            'events.manage' => 'Manage events',
            'talkshows.manage' => 'Manage talkshows',
            'participants.view' => 'View participant personal data',
            'registrations.manage' => 'Manage registrations',
            'checkins.create' => 'Record daily event check-ins',
            'attendance.create' => 'Record talkshow attendance',
            'waitlists.promote' => 'Promote talkshow waitlist entries',
            'statistics.view' => 'View event statistics',
            'reports.export' => 'Export authorized reports',
            'users.manage' => 'Manage staff accounts',
            'permissions.manage' => 'Manage roles and permissions',
        ])->mapWithKeys(function (string $name, string $slug) {
            $permission = Permission::updateOrCreate(['slug' => $slug], ['name' => $name]);

            return [$slug => $permission];
        });

        $superAdmin = Role::updateOrCreate(
            ['slug' => 'super_admin'],
            ['name' => 'Super Admin']
        );
        $panitia = Role::updateOrCreate(['slug' => 'panitia'], ['name' => 'Panitia']);
        $vendor = Role::updateOrCreate(['slug' => 'vendor'], ['name' => 'Vendor']);

        $superAdmin->belongsToMany(Permission::class, 'role_permissions')
            ->sync($permissions->pluck('id'));

        $panitia->belongsToMany(Permission::class, 'role_permissions')
            ->sync($permissions->only([
                'participants.view',
                'registrations.manage',
                'checkins.create',
                'attendance.create',
                'waitlists.promote',
                'statistics.view',
                'reports.export',
            ])->pluck('id'));

        $vendor->belongsToMany(Permission::class, 'role_permissions')
            ->sync([$permissions->get('statistics.view')->id]);
    }
}
