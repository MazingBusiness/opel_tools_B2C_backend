<?php

namespace App\Modules\Fulfilment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Fulfilment\Models\Challan;
use App\Modules\Fulfilment\Models\Invoice;
use App\Modules\Fulfilment\Services\InvoiceBuilder;
use App\Modules\Fulfilment\Support\Branches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdminInvoiceController extends Controller
{
    public function __construct(private readonly InvoiceBuilder $builder) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch' => ['nullable', Rule::in(Branches::codes())],
            'q' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = AdminAccess::scopeBranches(Invoice::query(), $request)
            ->withCount('challans')
            ->when($data['branch'] ?? null, fn (Builder $q, $b) => $q->where('branch', $b))
            ->when($data['q'] ?? null, fn (Builder $q, $s) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$s}%")->orWhere('billing_name', 'like', "%{$s}%")))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'branch' => $i->branch,
                'customer' => $i->billing_name,
                'city' => $i->billing_city,
                'challans_count' => $i->challans_count,
                'grand_total' => (string) $i->grand_total,
                'created_at' => $i->created_at?->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        AdminAccess::assertBranch($request, Invoice::query()->whereKey($id)->value('branch'));
        $i = Invoice::query()->with(['items', 'challans:id,number,invoice_id,order_id', 'challans.order:id,number'])->findOrFail($id);

        return response()->json(['ok' => true, 'invoice' => array_merge($i->toArray(), [
            'branch_name' => Branches::all()[$i->branch]['name'],
            'branch_state' => Branches::all()[$i->branch]['state'],
            'challans' => $i->challans->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'order_number' => $c->order?->number])->all(),
        ])]);
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'preview' => $this->builder->preview($this->challanIds($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $invoice = $this->builder->create($this->challanIds($request), $request->user()?->getAuthIdentifier());

        return $this->show($request, $invoice->id)->setStatusCode(201);
    }

    /** @return list<int> */
    private function challanIds(Request $request): array
    {
        $v = $request->validate([
            'challan_ids' => ['required', 'array', 'min:1', 'max:100'],
            'challan_ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_map('intval', $v['challan_ids']);
        // Any challan outside this admin's branches makes the whole request a 404.
        $outside = Challan::query()->whereIn('id', $ids)
            ->whereNotIn('branch', AdminAccess::admin($request)->allowedBranches())->exists();
        abort_if($outside, Response::HTTP_NOT_FOUND, 'Not found.');

        return $ids;
    }
}
