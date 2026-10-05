<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\AdminUserIndexRequest;
use App\Modules\Admin\Http\Resources\AdminShopperDetailResource;
use App\Modules\Admin\Http\Resources\AdminShopperResource;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin Users v0 — shoppers only (is_staff = false).
 * Read-only list/detail plus activate/deactivate. Staff ids always 404.
 */
class AdminUserController extends Controller
{
    /** Mirrors the catalog default page size. */
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    public function index(AdminUserIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $q = (string) ($filters['q'] ?? '');
        $status = $filters['status'] ?? 'all';
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE)));

        $query = $this->shoppers()->withCount('orders');

        if ($q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function (Builder $w) use ($like): void {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        if ($status === 'active') {
            $query->whereNull('disabled_at');
        } elseif ($status === 'disabled') {
            $query->whereNotNull('disabled_at');
        }

        $page = $query
            ->orderBy('created_at', $dir)
            ->orderBy('id', $dir)
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'ok' => true,
            'data' => AdminShopperResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->userPayload($this->findShopper($id));
    }

    public function deactivate(string $id): JsonResponse
    {
        $user = $this->findShopper($id);

        DB::transaction(function () use ($user): void {
            if ($user->disabled_at === null) {
                $user->forceFill(['disabled_at' => now()])->save();
            }

            $this->revokeShopperTokens($user);
        });

        return $this->userPayload($user);
    }

    public function activate(string $id): JsonResponse
    {
        $user = $this->findShopper($id);

        if ($user->disabled_at !== null) {
            $user->forceFill(['disabled_at' => null])->save();
        }

        return $this->userPayload($user);
    }

    /**
     * @return Builder<User>
     */
    private function shoppers(): Builder
    {
        return User::query()->where('is_staff', false);
    }

    /**
     * Staff (and unknown) ids are indistinguishable: both 404.
     */
    private function findShopper(string $id): User
    {
        $user = ctype_digit($id) && strlen($id) <= 18
            ? $this->shoppers()->whereKey((int) $id)->first()
            : null;

        if (! $user instanceof User) {
            abort(Response::HTTP_NOT_FOUND, 'User not found.');
        }

        return $user;
    }

    /**
     * Delete shopper tokens only. Any token carrying the "admin" ability is kept,
     * regardless of its name, so staff sessions can never be revoked from here.
     */
    private function revokeShopperTokens(User $user): void
    {
        $user->tokens()
            ->get()
            ->reject(fn (PersonalAccessToken $token): bool => in_array('admin', $token->abilities ?? [], true))
            ->each(fn (PersonalAccessToken $token) => $token->delete());
    }

    private function userPayload(User $user): JsonResponse
    {
        $user = $user->fresh();
        $user->load(['addresses' => fn ($q) => $q->orderByDesc('is_default')->orderBy('id')]);
        $user->loadCount('orders');

        return response()->json([
            'ok' => true,
            'user' => (new AdminShopperDetailResource($user))->resolve(),
        ]);
    }
}
