<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\AdminResource;
use App\Modules\Admin\Models\Admin;
use App\Modules\Admin\Models\AdminAuditLog;
use App\Modules\Admin\Support\AdminModules;
use App\Modules\Fulfilment\Support\Branches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super admin only: manage sub-admins. The super admin account itself is never
 * reachable here (404). A temporary password is returned once, on create and
 * reset, and is never stored or logged in readable form.
 */
class AdminAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Admin::query()
            ->where('is_super', false)
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->when(($data['status'] ?? null) === 'active', fn ($q) => $q->whereNull('disabled_at'))
            ->when(($data['status'] ?? null) === 'disabled', fn ($q) => $q->whereNotNull('disabled_at'))
            ->orderBy('name')
            ->paginate(20);

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn (Admin $a) => (new AdminResource($a))->resolve())->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'options' => $this->options(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $admin = $this->subAdmin($id);
        $audit = AdminAuditLog::query()->with('actor:id,name')->where('admin_id', $admin->id)
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (AdminAuditLog $l) => [
                'action' => $l->action,
                'changes' => $l->changes,
                'actor' => $l->actor?->name ?? 'System',
                'at' => $l->created_at?->toIso8601String(),
            ])->all();

        return response()->json([
            'ok' => true,
            'admin' => (new AdminResource($admin))->resolve(),
            'audit' => $audit,
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $password = $this->temporaryPassword();

        $admin = DB::transaction(function () use ($data, $password, $request): Admin {
            $admin = new Admin;
            $admin->forceFill($data + [
                'password' => $password,
                'is_super' => false,
                'must_change_password' => true,
                'created_by' => $request->user()->id,
            ])->save();
            AdminAuditLog::record($request->user()->id, $admin, 'created', $this->snapshot($admin));

            return $admin;
        });

        return $this->withPassword($admin, $password, Response::HTTP_CREATED);
    }

    /** Any change logs the admin out, so new access applies at once. */
    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $this->subAdmin($id);
        $data = $this->validated($request, $admin);

        DB::transaction(function () use ($admin, $data, $request): void {
            $before = $this->snapshot($admin);
            $admin->forceFill($data);
            if (! $admin->isDirty()) {
                return;
            }
            $admin->save();
            $after = $this->snapshot($admin);
            $changed = array_keys(array_filter($after, fn ($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
            AdminAuditLog::record($request->user()->id, $admin, 'updated', [
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
            ]);
            $admin->revokeTokens();
        });

        return response()->json(['ok' => true, 'admin' => (new AdminResource($admin->fresh()))->resolve()]);
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $admin = $this->subAdmin($id);
        $password = $this->temporaryPassword();

        DB::transaction(function () use ($admin, $password, $request): void {
            $admin->forceFill(['password' => $password, 'must_change_password' => true])->save();
            $admin->revokeTokens();
            AdminAuditLog::record($request->user()->id, $admin, 'password_reset');
        });

        return $this->withPassword($admin->fresh(), $password);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        return $this->setDisabled($request, $id, true);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        return $this->setDisabled($request, $id, false);
    }

    private function setDisabled(Request $request, int $id, bool $disabled): JsonResponse
    {
        $admin = $this->subAdmin($id);

        if ($admin->isDisabled() !== $disabled) {
            DB::transaction(function () use ($admin, $disabled, $request): void {
                $admin->forceFill(['disabled_at' => $disabled ? now() : null])->save();
                $admin->revokeTokens();
                AdminAuditLog::record($request->user()->id, $admin, $disabled ? 'deactivated' : 'activated');
            });
        }

        return response()->json(['ok' => true, 'admin' => (new AdminResource($admin->fresh()))->resolve()]);
    }

    private function subAdmin(int $id): Admin
    {
        $admin = Admin::query()->where('is_super', false)->find($id);
        abort_unless($admin instanceof Admin, Response::HTTP_NOT_FOUND, 'Admin not found.');

        return $admin;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Admin $admin = null): array
    {
        $request->merge(array_filter([
            'email' => is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : null,
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : null,
            'phone' => is_string($request->input('phone')) ? preg_replace('/\s+/', '', $request->input('phone')) : null,
        ], fn ($v) => $v !== null));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($admin?->id)],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'modules' => ['present', 'array'],
            'modules.*' => ['string', 'distinct', Rule::in(AdminModules::ALL)],
            'branches' => ['present', 'array'],
            'branches.*' => ['string', 'distinct', Rule::in(Branches::codes())],
        ]);

        // Stored in a fixed order so audit diffs compare cleanly.
        $data['modules'] = array_values(array_intersect(AdminModules::ALL, $data['modules']));
        $data['branches'] = array_values(array_intersect(Branches::codes(), $data['branches']));
        $data['phone'] = filled($data['phone'] ?? null) ? $data['phone'] : null;

        return $data;
    }

    /** @return array<string, mixed> */
    private function snapshot(Admin $admin): array
    {
        return [
            'name' => $admin->name,
            'email' => $admin->email,
            'phone' => $admin->phone,
            'modules' => array_values($admin->modules ?? []),
            'branches' => array_values($admin->branches ?? []),
        ];
    }

    private function temporaryPassword(): string
    {
        // Letters + digits only, so it is easy to read out or type.
        return Str::password(12, letters: true, numbers: true, symbols: false);
    }

    private function withPassword(Admin $admin, string $password, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'admin' => (new AdminResource($admin))->resolve(),
            'temporary_password' => $password,
        ], $status)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'modules' => AdminModules::ALL,
            'branches' => collect(Branches::all())->map(fn ($b, $code) => ['code' => $code, 'name' => $b['name']])->values()->all(),
        ];
    }
}
