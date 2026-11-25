<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Any active admin with an admin token. Until a temporary password is changed,
 * only the routes needed to change it (and to log out) work.
 */
class EnsureAdmin
{
    private const ALLOWED_WHILE_PASSWORD_PENDING = [
        'api/v1/admin/auth/me',
        'api/v1/admin/auth/password',
        'api/v1/admin/auth/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || $admin->isDisabled()) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
        }

        $token = $admin->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! in_array('admin', $token->abilities ?? [], true)) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
        }

        if ($admin->must_change_password && ! in_array($request->path(), self::ALLOWED_WHILE_PASSWORD_PENDING, true)) {
            return response()->json([
                'message' => 'Change your temporary password to continue.',
                'code' => 'password_change_required',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
