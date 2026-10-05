<?php

namespace App\Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_staff) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
        }

        $token = $user->currentAccessToken();

        // Require an explicit "admin" ability. Sanctum's default "*" (shopper tokens)
        // must not unlock /admin/* even if the user is staff.
        if (! $token instanceof PersonalAccessToken) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
        }

        $abilities = $token->abilities ?? [];

        if (! in_array('admin', $abilities, true)) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
        }

        return $next($request);
    }
}
