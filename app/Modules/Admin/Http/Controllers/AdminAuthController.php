<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\AdminLoginRequest;
use App\Modules\Admin\Http\Requests\UpdateAdminPasswordRequest;
use App\Modules\Admin\Http\Requests\UpdateAdminProfileRequest;
use App\Modules\Admin\Http\Resources\AdminResource;
use App\Modules\Admin\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** No forgot/reset-password flow: only the super admin resets sub-admins; the super admin recovers via artisan. */
class AdminAuthController extends Controller
{
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->validated('email')));
        $password = (string) $request->validated('password');

        $admin = Admin::query()->where('email', $email)->first();

        // Hash even when the email is unknown, so timing does not reveal which emails are admins.
        static $dummyHash = null;
        $dummyHash ??= Hash::make(Str::random(32));
        $passwordOk = Hash::check($password, $admin instanceof Admin ? $admin->password : $dummyHash);

        if (! $admin instanceof Admin || ! $passwordOk || $admin->isDisabled()) {
            return response()->json(['message' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED);
        }

        $admin->forceFill(['last_login_at' => now()])->save();
        $token = $admin->createToken(Admin::TOKEN_NAME, ['admin'])->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new AdminResource($admin))->resolve(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'user' => (new AdminResource($request->user()))->resolve(),
        ]);
    }

    public function updateProfile(UpdateAdminProfileRequest $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $admin->name = $request->validated('name');
        $admin->save();

        return response()->json([
            'ok' => true,
            'user' => (new AdminResource($admin->fresh()))->resolve(),
        ]);
    }

    /** Also how a sub-admin replaces a temporary password. Other sessions are logged out. */
    public function updatePassword(UpdateAdminPasswordRequest $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $currentToken = $admin->currentAccessToken();

        $admin->forceFill([
            'password' => (string) $request->validated('password'),
            'must_change_password' => false,
        ])->save();

        $query = $admin->tokens();
        if ($currentToken instanceof PersonalAccessToken) {
            $query->where('id', '!=', $currentToken->id);
        }
        $query->delete();

        return response()->json([
            'ok' => true,
            'user' => (new AdminResource($admin->fresh()))->resolve(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        Auth::forgetGuards();

        return response()->json(['ok' => true]);
    }
}
