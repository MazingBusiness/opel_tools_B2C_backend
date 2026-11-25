<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Models\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Branch scoping for fulfilment records. Out-of-branch records behave as missing (404). */
final class AdminAccess
{
    public static function admin(Request $request): Admin
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin, Response::HTTP_FORBIDDEN, 'Forbidden.');

        return $admin;
    }

    public static function scopeBranches(Builder $query, Request $request, string $column = 'branch'): Builder
    {
        return $query->whereIn($column, self::admin($request)->allowedBranches());
    }

    public static function assertBranch(Request $request, ?string $branch): void
    {
        if ($branch === null || ! in_array($branch, self::admin($request)->allowedBranches(), true)) {
            abort(Response::HTTP_NOT_FOUND, 'Not found.');
        }
    }
}
