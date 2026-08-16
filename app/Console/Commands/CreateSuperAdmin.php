<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    protected $signature = 'staff:create-super-admin
        {--name= : Super Admin display name}
        {--email= : Super Admin email address}
        {--allow-additional : Allow creation when another active Super Admin exists}
        {--generate-password : Generate and display a secure password once}';

    protected $description = 'Securely create the first ProBuild Super Admin account';

    public function handle(AuditLogger $auditLogger): int
    {
        $existingAdmin = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('slug', 'super_admin'))
            ->exists();

        $allowAdditional = (bool) $this->option('allow-additional');

        if ($existingAdmin && ! $allowAdditional) {
            $this->error('An active Super Admin already exists. Use the protected staff user API to manage accounts.');

            return self::FAILURE;
        }

        $role = Role::where('slug', 'super_admin')->first();

        if (! $role) {
            $this->error('The Super Admin role is missing. Run the access-control seeder first.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Name')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $generatePassword = (bool) $this->option('generate-password');
        $password = $generatePassword
            ? Str::password(10, true, true, false, false)
            : (string) $this->secret('Password (minimum 8 characters)');
        $confirmation = $generatePassword
            ? $password
            : (string) $this->secret('Confirm password');
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use (
            $auditLogger,
            $name,
            $email,
            $password,
            $role,
            $allowAdditional,
        ): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_active' => true,
            ]);
            $user->roles()->attach($role->id);
            $auditLogger->record(
                $allowAdditional
                    ? 'user.additional_super_admin_created'
                    : 'user.bootstrap_super_admin_created',
                subject: $user,
                metadata: [
                    'role' => 'super_admin',
                ],
            );

            return $user;
        });

        $this->info("Super Admin {$user->email} created successfully.");

        if ($generatePassword) {
            $this->warn('Generated password (shown once):');
            $this->line($password);
        }

        return self::SUCCESS;
    }
}
