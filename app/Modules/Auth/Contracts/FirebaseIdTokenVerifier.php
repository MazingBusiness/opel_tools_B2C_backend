<?php

namespace App\Modules\Auth\Contracts;

use App\Modules\Auth\Support\FirebaseIdentity;

interface FirebaseIdTokenVerifier
{
    public function verify(string $idToken): FirebaseIdentity;
}
