<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffUserRequest;
use App\Http\Requests\UpdateStaffUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $users = User::query()
            ->with('roles:id,name,slug')
            ->orderBy('name')
            ->paginate($data['per_page'] ?? 25)
            ->through(fn (User $user) => $this->userData($user));

        return response()->json(['data' => $users]);
    }

    public function store(StoreStaffUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $role = Role::where('slug', $data['role'])->firstOrFail();

        $user = DB::transaction(function () use ($request, $data, $role): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);
            $user->roles()->sync([$role->id]);
            $this->auditLogger->record('user.created', $request->user(), $user, metadata: [
                'role' => $role->slug,
                'is_active' => $user->is_active,
            ]);

            return $user;
        });

        return response()->json(['data' => $this->userData($user->load('roles'))], 201);
    }

    public function update(UpdateStaffUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $newRole = isset($data['role']) ? Role::where('slug', $data['role'])->firstOrFail() : null;
        $currentRole = $user->roles()->first();
        $wouldRemoveSuperAdmin = $user->hasRole('super_admin') && (
            ($newRole && $newRole->slug !== 'super_admin')
            || (array_key_exists('is_active', $data) && ! $data['is_active'])
        );

        if ($user->is($request->user()) && $wouldRemoveSuperAdmin) {
            throw ValidationException::withMessages([
                'user' => ['You cannot remove your own active Super Admin access.'],
            ]);
        }

        if ($wouldRemoveSuperAdmin && $this->activeSuperAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'user' => ['At least one active Super Admin must remain.'],
            ]);
        }

        $before = ['role' => $currentRole?->slug, 'is_active' => $user->is_active];

        DB::transaction(function () use ($request, $user, $data, $newRole, $before): void {
            $user->fill(collect($data)->only(['name', 'email', 'password', 'is_active'])->all())->save();

            if ($newRole) {
                $user->roles()->sync([$newRole->id]);
            }

            if (! $user->is_active) {
                $user->accessTokens()->whereNull('revoked_at')->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->auditLogger->record('user.updated', $request->user(), $user, metadata: [
                'before' => $before,
                'after' => [
                    'role' => $newRole?->slug ?? $before['role'],
                    'is_active' => $user->is_active,
                ],
                'password_changed' => array_key_exists('password', $data),
            ]);
        });

        return response()->json(['data' => $this->userData($user->load('roles'))]);
    }

    public function roles(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name,slug')
            ->whereIn('slug', ['super_admin', 'panitia', 'vendor'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'permissions' => $role->permissions->map->only(['name', 'slug'])->values(),
            ]);

        return response()->json(['data' => $roles]);
    }

    private function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('slug', 'super_admin'))
            ->count();
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $user->roles->map->only(['id', 'name', 'slug'])->values(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
