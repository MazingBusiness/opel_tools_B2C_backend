<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\LoginIdentifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    /**
     * Register a shopper from B2B, or return the existing one.
     * Fills only null name, email, or phone. Does not overwrite, re-enable, or verify.
     *
     * @param  array{name?: string|null, email?: string|null, phone?: string|null}  $data
     * @return array{user: User, created: bool}
     */
    public function registerFromB2b(array $data): array
    {
        $name = $this->blankToNull($data['name'] ?? null);
        $email = $this->blankToNull(
            isset($data['email']) ? strtolower(trim((string) $data['email'])) : null,
        );
        $phone = filled($data['phone'] ?? null)
            ? LoginIdentifier::parse((string) $data['phone'])->value
            : null;

        if ($email === null && $phone === null) {
            throw ValidationException::withMessages([
                'email' => 'Provide an email or a phone number.',
            ]);
        }

        try {
            return $this->persistB2bRegistration($name, $email, $phone);
        } catch (UniqueConstraintViolationException) {
            return $this->persistB2bRegistration($name, $email, $phone);
        }
    }

    /**
     * @return array{user: User, created: bool}
     */
    private function persistB2bRegistration(?string $name, ?string $email, ?string $phone): array
    {
        return DB::transaction(function () use ($name, $email, $phone): array {
            $byEmail = $this->lockShopper('email', $email);
            $byPhone = $this->lockShopper('phone', $phone);

            if ($byEmail !== null && $byPhone !== null && $byEmail->id !== $byPhone->id) {
                throw new ConflictHttpException('Email and phone belong to different shoppers.');
            }

            $user = $byEmail ?? $byPhone;

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                ]);

                return ['user' => $user, 'created' => true];
            }

            if ($user->is_staff) {
                throw new ConflictHttpException('That account cannot be registered from B2B.');
            }

            if ($user->isDisabled()) {
                return ['user' => $user, 'created' => false];
            }

            $updates = [];

            if ($name !== null && $user->name === null) {
                $updates['name'] = $name;
            }

            if ($email !== null && $user->email === null) {
                $this->ensureIdentifierAvailable('email', $email, $user);
                $updates['email'] = $email;
            }

            if ($phone !== null && $user->phone === null) {
                $this->ensureIdentifierAvailable('phone', $phone, $user);
                $updates['phone'] = $phone;
            }

            if ($updates !== []) {
                $user->fill($updates)->save();
            }

            return ['user' => $user->refresh(), 'created' => false];
        });
    }

    private function lockShopper(string $column, ?string $value): ?User
    {
        if ($value === null) {
            return null;
        }

        return User::query()->where($column, $value)->lockForUpdate()->first();
    }

    private function ensureIdentifierAvailable(string $column, string $value, User $user): void
    {
        $taken = User::query()
            ->where($column, $value)
            ->where('id', '!=', $user->id)
            ->lockForUpdate()
            ->exists();

        if (! $taken) {
            return;
        }

        $message = $column === 'email'
            ? 'That email is already in use.'
            : 'That phone number is already in use.';

        throw new ConflictHttpException($message);
    }

    private function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
