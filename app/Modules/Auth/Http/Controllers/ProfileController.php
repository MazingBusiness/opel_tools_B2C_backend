<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\UpdateProfileRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->userPayload($user);
    }

    public function update(UpdateProfileRequest $request, AuthService $auth): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user = $auth->updateProfile($user, $request->validated());

        return $this->userPayload($user);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            $user->tokens()->delete();
        }

        Auth::forgetGuards();

        return response()->json(['ok' => true]);
    }

    private function userPayload(User $user): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'user' => (new UserResource($user))->resolve(),
            'profile_complete' => $user->profileComplete(),
        ]);
    }
}
