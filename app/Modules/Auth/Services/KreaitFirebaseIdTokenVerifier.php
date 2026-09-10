<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\FirebaseIdTokenVerifier;
use App\Modules\Auth\Support\FirebaseIdentity;
use Illuminate\Validation\ValidationException;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Throwable;

class KreaitFirebaseIdTokenVerifier implements FirebaseIdTokenVerifier
{
    public function verify(string $idToken): FirebaseIdentity
    {
        try {
            $token = Firebase::auth()->verifyIdToken($idToken);
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'id_token' => 'That Google sign-in is invalid or has expired.',
            ]);
        }

        $claims = $token->claims();
        $uid = (string) $claims->get('sub', '');
        $email = strtolower(trim((string) $claims->get('email', '')));

        if ($uid === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'id_token' => 'That Google account has no email we can use.',
            ]);
        }

        $projectId = config('services.firebase.project_id');
        $audience = $claims->get('aud');
        $audience = is_array($audience) ? ($audience[0] ?? null) : $audience;

        if (filled($projectId) && (string) $audience !== (string) $projectId) {
            throw ValidationException::withMessages([
                'id_token' => 'That Google sign-in is invalid or has expired.',
            ]);
        }

        $provider = data_get($claims->get('firebase'), 'sign_in_provider');

        if ($provider !== 'google.com') {
            throw ValidationException::withMessages([
                'id_token' => 'Sign in with Google to continue.',
            ]);
        }

        $name = $claims->get('name');
        $avatar = $claims->get('picture');

        return new FirebaseIdentity(
            uid: $uid,
            email: $email,
            emailVerified: (bool) $claims->get('email_verified', false),
            name: filled($name) ? (string) $name : null,
            avatar: filled($avatar) ? (string) $avatar : null,
        );
    }
}
