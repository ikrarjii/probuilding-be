<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffLoginRequest;
use App\Models\StaffAccessToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function login(StaffLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->with('roles.permissions')->where('email', $data['email'])->first();
        $passwordHash = $user?->password
            ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $passwordIsValid = Hash::check($data['password'], $passwordHash);

        if (! $user || ! $passwordIsValid || ! $user->is_active || $user->roles->isEmpty()) {
            $this->auditLogger->record(
                'auth.login_failed',
                subject: $user,
                metadata: [
                    'email_sha256' => hash('sha256', $data['email']),
                    'ip_address' => $request->ip(),
                ],
            );

            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        [$plainToken, $token] = DB::transaction(function () use ($user, $data, $request): array {
            $plainToken = bin2hex(random_bytes(32));
            $token = StaffAccessToken::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $plainToken),
                'name' => $data['device_name'] ?? 'staff-web',
                'expires_at' => now()->addMinutes(max(15, (int) config('staff.access_token_ttl_minutes'))),
            ]);

            $activeTokenIds = StaffAccessToken::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->latest('created_at')
                ->pluck('id');
            $limit = max(1, (int) config('staff.max_active_tokens_per_user'));

            if ($activeTokenIds->count() > $limit) {
                StaffAccessToken::whereIn('id', $activeTokenIds->slice($limit))
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
            }

            $user->forceFill(['last_login_at' => now()])->save();
            $this->auditLogger->record('auth.login', $user, $token, metadata: [
                'device_name' => $token->name,
                'ip_address' => $request->ip(),
            ]);

            return [$plainToken, $token];
        });

        return response()->json(['data' => [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->expires_at->toIso8601String(),
            'user' => $this->userData($user),
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('roles.permissions');

        return response()->json(['data' => ['user' => $this->userData($user)]]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var StaffAccessToken $token */
        $token = $request->attributes->get('staff_access_token');

        DB::transaction(function () use ($request, $token): void {
            $token->update(['revoked_at' => now()]);
            $this->auditLogger->record('auth.logout', $request->user(), $token);
        });

        return response()->json(['data' => ['logged_out' => true]]);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('slug')->values(),
            'permissions' => $user->roles
                ->flatMap->permissions
                ->pluck('slug')
                ->unique()
                ->values(),
        ];
    }
}
