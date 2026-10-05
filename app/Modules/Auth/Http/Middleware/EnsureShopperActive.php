<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\DisabledAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks authenticated shopper requests once an admin has disabled the account.
 * Must run after auth:sanctum. Not applied to /admin/* routes.
 */
class EnsureShopperActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDisabled()) {
            return DisabledAccount::response();
        }

        return $next($request);
    }
}
