<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** admin.module:<name> — runs after `admin`. Super admins pass every module. */
class EnsureAdminModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || ! $admin->canUseModule($module)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to this section.');
        }

        return $next($request);
    }
}
