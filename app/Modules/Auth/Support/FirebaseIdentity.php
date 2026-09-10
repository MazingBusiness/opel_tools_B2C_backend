<?php

namespace App\Modules\Auth\Support;

final readonly class FirebaseIdentity
{
    public function __construct(
        public string $uid,
        public string $email,
        public bool $emailVerified,
        public ?string $name,
        public ?string $avatar,
    ) {}
}
