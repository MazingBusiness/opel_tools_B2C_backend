<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\LoginIdentifier;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function loginOrRegister(LoginIdentifier $identifier): User
    {
        if ($identifier->channel === LoginIdentifier::CHANNEL_EMAIL) {
            $user = User::query()->where('email', $identifier->value)->first();

            if ($user === null) {
                return User::query()->create([
                    'email' => $identifier->value,
                    'email_verified_at' => now(),
                ]);
            }

            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        }

        $user = User::query()->where('phone', $identifier->value)->first();

        if ($user === null) {
            return User::query()->create([
                'phone' => $identifier->value,
                'phone_verified_at' => now(),
            ]);
        }

        $user->forceFill(['phone_verified_at' => now()])->save();

        return $user;
    }

    /**
     * @param  array{name?: string|null, email?: string|null, phone?: string|null, avatar?: string|null}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        if (array_key_exists('email', $data) && filled($data['email'])) {
            $email = strtolower(trim((string) $data['email']));
            $taken = User::query()
                ->where('email', $email)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'email' => 'That email is already in use.',
                ]);
            }

            $data['email'] = $email;
        }

        if (array_key_exists('phone', $data) && filled($data['phone'])) {
            $phone = LoginIdentifier::parse((string) $data['phone'])->value;
            $taken = User::query()
                ->where('phone', $phone)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'phone' => 'That phone number is already in use.',
                ]);
            }

            $data['phone'] = $phone;
        }

        $user->fill($data)->save();

        return $user->refresh();
    }
}
