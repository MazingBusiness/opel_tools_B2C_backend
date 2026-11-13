<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\UpdateAdminHomeRequest;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\HomeLayout;
use App\Modules\Catalog\Services\HomePage;
use App\Modules\Catalog\Support\CatalogCache;
use App\Modules\Catalog\Support\CatalogSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Homepage layout editor. One row, whole-layout saves, version-guarded (409).
 */
class AdminHomeController extends Controller
{
    public function __construct(private readonly HomePage $home) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(UpdateAdminHomeRequest $request): JsonResponse
    {
        $expected = (int) $request->validated('expected_version');
        $sections = $this->normalise($request->validated('sections') ?? []);
        $userId = $request->user()?->id;

        $saved = DB::transaction(function () use ($expected, $sections, $userId): bool {
            $row = HomeLayout::query()->lockForUpdate()->find(HomeLayout::ROW_ID);
            $current = $row?->version ?? 0;
            if ($current !== $expected) {
                return false;
            }

            HomeLayout::query()->updateOrCreate(['id' => HomeLayout::ROW_ID], [
                'sections' => $sections,
                'version' => $current + 1,
                'updated_by' => $userId,
            ]);

            return true;
        });

        if (! $saved) {
            return response()->json([
                'message' => 'The homepage was changed elsewhere (another tab or user) after you opened it. Reload to get the latest version, then re-apply your edits.',
                'current' => $this->payload(),
            ], Response::HTTP_CONFLICT);
        }

        CatalogCache::bump();

        return response()->json($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $layout = HomeLayout::query()->find(HomeLayout::ROW_ID);
        $groups = CatalogSort::byAdminOrder(CategoryGroup::query()->orderBy('name')->get());
        $visible = CategoryGroup::query()->visible()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return [
            'version' => $layout?->version ?? 0,
            'is_default' => $layout === null,
            'updated_at' => $layout?->updated_at?->toIso8601String(),
            'sections' => $this->home->configuredSections(),
            'groups' => $groups->map(fn (CategoryGroup $g): array => [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'slug' => (string) $g->slug,
                'visible' => in_array((int) $g->id, $visible, true),
            ])->values()->all(),
            'tones' => UpdateAdminHomeRequest::TONES,
        ];
    }

    /**
     * Keep only each type's fields, with stable defaults.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function normalise(array $sections): array
    {
        return array_values(array_map(function (array $s): array {
            $base = ['id' => $s['id'], 'type' => $s['type'], 'hidden' => (bool) ($s['hidden'] ?? false)];

            return match ($s['type']) {
                'hero' => $base + ['slides' => array_values(array_map(fn (array $slide): array => [
                    'image_url' => $slide['image_url'],
                    'link_url' => $slide['link_url'] ?? null,
                ], $s['slides']))],
                'category_band' => $base + [
                    'group_id' => (int) $s['group_id'],
                    'title' => $s['title'] ?? null,
                    'product_limit' => (int) ($s['product_limit'] ?? HomePage::DEFAULT_PRODUCT_LIMIT),
                    'pinned_part_nos' => array_values(array_unique(array_filter(array_map(
                        fn ($p): string => trim((string) $p),
                        $s['pinned_part_nos'] ?? [],
                    )))),
                ],
                'banner_row' => $base + ['banners' => array_values(array_map(fn (array $b): array => [
                    'eyebrow' => $b['eyebrow'] ?? null,
                    'title' => $b['title'],
                    'subtitle' => $b['subtitle'] ?? null,
                    'badge' => $b['badge'] ?? null,
                    'cta_label' => $b['cta_label'] ?? null,
                    'href' => $b['href'],
                    'image_url' => $b['image_url'],
                    'tone' => $b['tone'] ?? null,
                ], $s['banners']))],
            };
        }, $sections));
    }
}
