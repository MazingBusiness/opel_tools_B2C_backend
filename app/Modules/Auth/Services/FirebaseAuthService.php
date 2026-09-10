<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\FirebaseIdTokenVerifier;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\FirebaseIdentity;

class FirebaseAuthService
{
    public function __construct(private FirebaseIdTokenVerifier $verifier) {}

    public function login(string $idToken): User
    {
        return $this->loginOrRegister($this->verifier->verify($idToken));
    }

    public function loginOrRegister(FirebaseIdentity $identity): User
    {
        $user = User::query()->where('firebase_uid', $identity->uid)->first();

        if ($user === null) {
            $user = User::query()->where('email', $identity->email)->first();
        }

        if ($user === null) {
            $user = new User;
        }

        $attributes = [
            'firebase_uid' => $identity->uid,
            'email' => $identity->email,
        ];

        if ($identity->emailVerified) {
            $attributes['email_verified_at'] = $user->email_verified_at ?? now();
        }

        if ($user->name === null && filled($identity->name)) {
            $attributes['name'] = $identity->name;
        }

        if ($user->avatar === null && filled($identity->avatar)) {
            $attributes['avatar'] = $identity->avatar;
        }

        $user->forceFill($attributes)->save();

        return $user->refresh();
    }
}
