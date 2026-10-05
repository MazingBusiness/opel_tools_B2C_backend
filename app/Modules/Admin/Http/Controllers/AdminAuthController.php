<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\AdminForgotPasswordRequest;
use App\Modules\Admin\Http\Requests\AdminLoginRequest;
use App\Modules\Admin\Http\Requests\AdminResetPasswordRequest;
use App\Modules\Admin\Http\Requests\UpdateAdminPasswordRequest;
use App\Modules\Admin\Http\Requests\UpdateAdminProfileRequest;
use App\Modules\Admin\Http\Resources\AdminUserResource;
use App\Modules\Auth\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthController extends Controller
{
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->validated('email')));
        $password = (string) $request->validated('password');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (
            $user === null
            || ! filled($user->password)
            || ! Hash::check($password, $user->password)
            || ! $user->is_staff
        ) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token = $user->createToken('opel-b2c-admin', ['admin'])->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new AdminUserResource($user))->resolve(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'ok' => true,
            'user' => (new AdminUserResource($user))->resolve(),
        ]);
    }

    public function updateProfile(UpdateAdminProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->name = $request->validated('name');
        $user->save();

        return response()->json([
            'ok' => true,
            'user' => (new AdminUserResource($user->fresh()))->resolve(),
        ]);
    }

    public function updatePassword(UpdateAdminPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        $user->password = (string) $request->validated('password');
        $user->save();

        $query = $user->tokens()->where('name', 'opel-b2c-admin');

        if ($currentToken instanceof PersonalAccessToken) {
            $query->where('id', '!=', $currentToken->id);
        }

        $query->delete();

        return response()->json(['ok' => true]);
    }

    public function forgotPassword(AdminForgotPasswordRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user !== null && $user->is_staff) {
            Password::broker()->sendResetLink(['email' => $email]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'If that account exists, a reset link has been sent.',
        ]);
    }

    public function resetPassword(AdminResetPasswordRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');
        $accepted = false;

        $status = Password::broker()->reset(
            $credentials,
            function (User $user, string $password) use (&$accepted): void {
                if (! $user->is_staff) {
                    return;
                }

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
                $accepted = true;

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET || ! $accepted) {
            return response()->json([
                'message' => 'Unable to reset password.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        Auth::forgetGuards();

        return response()->json(['ok' => true]);
    }
}
