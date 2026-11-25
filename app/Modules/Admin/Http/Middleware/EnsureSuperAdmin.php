<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** admin.super — runs after `admin`. */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || ! $admin->is_super) {
            abort(Response::HTTP_FORBIDDEN, 'Only the super admin can do this.');
        }

        return $next($request);
    }
}
