<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\AdminCatalogIndexRequest;
use App\Modules\Admin\Http\Requests\ResetAdminCatalogRequest;
use App\Modules\Admin\Http\Requests\UpdateAdminCatalogRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\CatalogOverrideService;
use App\Modules\Catalog\Support\CatalogVisibility;
use App\Modules\Catalog\Support\PartNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin Catalog v0: B2B base vs admin overrides for groups, categories
 * (incl. subcategories), products and variants. Staff only (auth:sanctum + admin).
 */
class AdminCatalogController extends Controller
{
    public function __construct(private CatalogOverrideService $overrides) {}

    public function index(AdminCatalogIndexRequest $request, string $entity): JsonResponse
    {
        $f = $request->validated();
        $perPage = min(50, max(1, (int) ($f['per_page'] ?? 20)));
        $q = (string) ($f['q'] ?? '');
        $filter = $f['filter'] ?? null;

        /** @var Builder<Model> $query */
        $query = match ($entity) {
            'groups' => CategoryGroup::query()->withCount('categories'),
            'categories' => Category::query()->with(['categoryGroup', 'parent'])->withCount('children'),
            'products' => Product::query()->with(['category', 'categoryGroup', 'defaultVariant', 'variants.catalogOverride']),
        };

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function (Builder $w) use ($q, $like, $entity): void {
                $w->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhereHas('catalogOverride', fn (Builder $o) => $o->where('name', 'like', $like));
                if (ctype_digit($q)) {
                    $w->orWhere($w->qualifyColumn('id'), (int) $q);
                }
                if ($entity === 'products') {
                    $normalized = PartNumber::normalize($q);
                    $w->orWhereHas('variants', fn (Builder $v) => $v->where('part_no_normalized', 'like', addcslashes($normalized, '%_\\').'%'));
                }
            });
        }

        if ($entity === 'categories') {
            if (! empty($f['group_id'])) {
                $query->where('category_group_id', (int) $f['group_id']);
            }
            if (! empty($f['parent_id'])) {
                $query->where('parent_id', (int) $f['parent_id']);
            }
            if (($f['level'] ?? null) === 'top') {
                $query->whereNull('parent_id');
            } elseif (($f['level'] ?? null) === 'sub') {
                $query->whereNotNull('parent_id');
            }
        }

        if ($entity === 'products') {
            if (! empty($f['group_id'])) {
                $query->where('group_id', (int) $f['group_id']);
            }
            if (! empty($f['category_id'])) {
                $query->where('category_id', (int) $f['category_id']);
            }
        }

        $columns = array_keys(CatalogOverrideService::FIELDS[$entity]);
        if ($filter === 'hidden') {
            $query->whereHas('catalogOverride', fn (Builder $o) => $o->where('hidden', true));
        } elseif ($filter === 'overridden') {
            $anyNotNull = function (array $cols): \Closure {
                return function (Builder $o) use ($cols): void {
                    $o->where(function (Builder $w) use ($cols): void {
                        foreach ($cols as $col) {
                            $w->orWhereNotNull($col);
                        }
                    });
                };
            };
            $query->where(function (Builder $w) use ($anyNotNull, $columns, $entity): void {
                $w->whereHas('catalogOverride', $anyNotNull($columns));
                if ($entity === 'products') {
                    $w->orWhereHas('variants.catalogOverride', $anyNotNull(array_keys(CatalogOverrideService::FIELDS['variants'])));
                }
            });
        }

        /** @var LengthAwarePaginator<int, Model> $page */
        $page = $query->orderBy($query->qualifyColumn('id'))->paginate($perPage)->withQueryString();

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn (Model $m): array => $this->row($entity, $m))->values(),
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

    public function show(string $entity, int $id): JsonResponse
    {
        return response()->json($this->payload($entity, $id));
    }

    public function update(UpdateAdminCatalogRequest $request, string $entity, int $id): JsonResponse
    {
        $model = $this->overrides->find($entity, $id);
        $fields = (array) $request->validated('fields');
        if ($fields === []) {
            throw ValidationException::withMessages(['fields' => 'Send at least one field (null resets it to B2B).']);
        }
        $values = $this->overrides->normalise($entity, $model, $fields);
        $this->overrides->assertPriceOrder($entity, $model, $values);

        return $this->writeOrConflict($entity, $id, (int) $request->validated('expected_version'), $values, $request->user()?->id);
    }

    public function reset(ResetAdminCatalogRequest $request, string $entity, int $id): JsonResponse
    {
        $model = $this->overrides->find($entity, $id);
        $registry = array_keys(CatalogOverrideService::FIELDS[$entity]);
        $fields = $request->validated('fields') ?? $registry;
        $unknown = array_diff($fields, $registry);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['fields' => 'Unknown or locked field(s): '.implode(', ', $unknown).'.']);
        }
        $values = array_fill_keys(array_values($fields), null);
        // Resetting one price to B2B can invert unit_price vs mrp / list_price too.
        $this->overrides->assertPriceOrder($entity, $model, $values);

        return $this->writeOrConflict($entity, $id, (int) $request->validated('expected_version'), $values, $request->user()?->id);
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $values
     */
    private function writeOrConflict(string $entity, int $id, int $expected, array $values, ?int $userId): JsonResponse
    {
        if (! $this->overrides->write($entity, $id, $expected, $values, $userId)) {
            return response()->json([
                'message' => 'This item was changed elsewhere (another tab or user) after you opened it. Reload to get the latest version, then re-apply your edits.',
                'current' => $this->payload($entity, $id),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json($this->payload($entity, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $entity, int $id): array
    {
        $model = $this->overrides->find($entity, $id);
        $config = $this->overrides->config($entity);
        $row = $model->overrideRow();

        $payload = [
            'ok' => true,
            'entity' => $entity,
            'id' => (int) $model->getKey(),
            'version' => $this->overrides->version($model),
            'override_updated_at' => $row?->updated_at?->toIso8601String(),
            'overridden_fields' => $this->overrides->overriddenFields($model),
            'item' => $this->summary($entity, $model),
            'locked' => $this->locked($entity, $model),
            'fields' => $this->overrides->fieldRows($entity, $model),
            'seo' => $config['seo'] !== null ? [
                'type' => $config['seo'],
                'key' => (string) $model->getKey(),
                'admin_path' => '/seo/'.$config['seo'].'/'.$model->getKey(),
            ] : null,
        ];

        if ($entity === 'products') {
            /** @var Product $model */
            $model->load('variants.catalogOverride');
            $payload['variants'] = $model->variants->map(fn (ProductVariant $v): array => [
                'id' => (int) $v->id,
                'part_no' => $v->part_no,
                'is_default' => (bool) $v->is_default,
                'version' => $this->overrides->version($v),
                'overridden_fields' => $this->overrides->overriddenFields($v),
                'locked' => $this->locked('variants', $v),
                'fields' => $this->overrides->fieldRows('variants', $v),
            ])->values()->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $entity, Model $m): array
    {
        $base = [
            'id' => (int) $m->getKey(),
            'overridden_fields' => $this->overrides->overriddenFields($m),
            'sort_order' => $m->adminSortOrder(),
            'hidden' => $m->isHiddenByOverride(),
        ];

        return $base + $this->summary($entity, $m);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(string $entity, Model $m): array
    {
        return match ($entity) {
            'groups' => [
                'name' => $m->name,
                'b2b_name' => $m->b2bValue('name'),
                'slug' => $m->slug,
                'public' => (bool) $m->is_active,
                'categories_count' => $m->categories_count ?? $m->categories()->count(),
            ],
            'categories' => [
                'name' => $m->name,
                'b2b_name' => $m->b2bValue('name'),
                'slug' => $m->slug,
                'public' => (bool) $m->is_active,
                'level' => $m->parent_id === null ? 'top' : 'sub',
                'group' => $m->categoryGroup ? ['id' => (int) $m->categoryGroup->id, 'name' => $m->categoryGroup->name] : null,
                'parent' => $m->parent ? ['id' => (int) $m->parent->id, 'name' => $m->parent->name] : null,
                'children_count' => $m->children_count ?? $m->children()->count(),
                'hidden_by_parent' => ! $m->isHiddenByOverride() && CatalogVisibility::categoryHidden((int) $m->id),
            ],
            'products' => $this->productSummary($m),
            'variants' => [
                'part_no' => $m->part_no,
                'is_default' => (bool) $m->is_default,
                'product' => $m->product ? ['id' => (int) $m->product->id, 'name' => $m->product->name] : null,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function productSummary(Product $p): array
    {
        $v = $p->defaultVariant;
        $variantOverridden = $p->relationLoaded('variants')
            ? $p->variants->contains(fn (ProductVariant $x): bool => $this->overrides->overriddenFields($x) !== [])
            : false;

        return [
            'name' => $p->name,
            'b2b_name' => $p->b2bValue('name'),
            'slug' => $p->slug,
            'thumbnail_img' => $p->thumbnail_img,
            'public' => (bool) $p->published && (bool) $p->approved,
            'b2b_published' => (bool) $p->b2bValue('published') && (bool) $p->approved,
            'hidden_by_category' => ! $p->isHiddenByOverride() && $p->hiddenOnStorefront(),
            'group' => $p->categoryGroup ? ['id' => (int) $p->categoryGroup->id, 'name' => $p->categoryGroup->name] : null,
            'category' => $p->category ? ['id' => (int) $p->category->id, 'name' => $p->category->name] : null,
            'part_no' => $v?->part_no,
            'unit_price' => $v?->unit_price,
            'b2b_unit_price' => $v?->b2bValue('unit_price'),
            'current_stock' => $v?->effectiveStock(),
            'b2b_current_stock' => $v?->b2bValue('current_stock'),
            'variants_overridden' => $variantOverridden,
        ];
    }

    /**
     * @return list<array{field: string, value: mixed}>
     */
    private function locked(string $entity, Model $m): array
    {
        $fields = match ($entity) {
            'groups' => ['id', 'slug', 'is_active'],
            'categories' => ['id', 'slug', 'category_group_id', 'parent_id', 'is_active'],
            'products' => ['id', 'slug', 'group_id', 'category_id', 'brand_id', 'published', 'approved'],
            'variants' => ['id', 'part_no', 'product_id', 'options'],
        };

        return array_map(fn (string $f): array => [
            'field' => $f,
            'value' => $m->b2bValue($f),
        ], $fields);
    }
}
