<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\GoogleLoginRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\FirebaseAuthService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GoogleAuthController extends Controller
{
    public function store(GoogleLoginRequest $request, FirebaseAuthService $auth): JsonResponse
    {
        if (! filled(config('services.firebase.credentials'))) {
            throw new HttpException(503, 'Google sign-in is not configured.');
        }

        $user = $auth->login($request->validated('id_token'));
        $token = $user->createToken('opel-b2c')->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve(),
            'profile_complete' => $user->profileComplete(),
        ]);
    }
}
