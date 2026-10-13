<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Seo\Models\SeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'https://shop.example.com';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        config(['seo.storefront_url' => self::SHOP, 'seo.cache_ttl' => 300]);

        $admin = User::factory()->staff()->create(['email' => 'seo-admin@example.com']);
        $this->adminToken = $admin->createToken('opel-b2c-admin', ['admin'])->plainTextToken;
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->adminToken);
    }

    private function seo(string $path): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([])->getJson('/api/v1/seo?path='.urlencode($path));
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function product(array $attrs = []): Product
    {
        $group = CategoryGroup::factory()->create(['name' => 'POWER TOOLS', 'slug' => 'power-tools']);
        $category = Category::factory()->create([
            'name' => 'AIR BLOWER', 'slug' => 'air-blower', 'category_group_id' => $group->id,
        ]);

        return Product::factory()->create(array_merge([
            'name' => 'Electric Blower 600W',
            'category_id' => $category->id,
            'group_id' => $group->id,
            'description' => '<p>Powerful <b>600W</b> blower &amp; dust cleaner.</p>',
            'thumbnail_img' => 'https://cdn.example.com/thumb.png',
            'photos' => ['https://cdn.example.com/a.png', 'https://cdn.example.com/b.png'],
        ], $attrs));
    }

    /**
     * Admin save with expected_version = the row's current version (0 if none),
     * i.e. what a freshly loaded admin form would send. Explicit keys win.
     *
     * @param  array<string, mixed>  $body
     */
    private function saveSeo(string $url, array $body): \Illuminate\Testing\TestResponse
    {
        if (! array_key_exists('expected_version', $body)
            && preg_match('#/admin/seo/([a-z_]+)/([^/?]+)#', $url, $m) === 1) {
            $body['expected_version'] = (int) (SeoMeta::query()
                ->where('target_type', $m[1])->where('target_key', $m[2])->value('version') ?? 0);
        }

        return $this->asAdmin()->putJson($url, $body);
    }

    /** @return array<string, mixed> */
    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'seo_title' => 'Custom title',
            'meta_description' => 'Custom description',
            'robots' => 'index',
        ], $overrides);
    }

    // ---- defaults ----------------------------------------------------------

    public function test_product_defaults(): void
    {
        $product = $this->product();

        $res = $this->seo('/products/'.$product->id)->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('type', 'product')
            ->assertJsonPath('title', 'Electric Blower 600W | OPEL Tools')
            ->assertJsonPath('description', 'Powerful 600W blower & dust cleaner.')
            ->assertJsonPath('h1', 'Electric Blower 600W')
            ->assertJsonPath('h1_is_override', false)
            ->assertJsonPath('robots', 'index,follow')
            ->assertJsonPath('canonical_url', self::SHOP.'/products/'.$product->id)
            ->assertJsonPath('og.image', 'https://cdn.example.com/thumb.png')
            ->assertJsonPath('og.type', 'product')
            ->assertJsonPath('breadcrumbs.1.label', 'Power Tools')
            ->assertJsonPath('breadcrumbs.1.href', '/category/power-tools')
            ->assertJsonPath('breadcrumbs.2.href', '/category/power-tools/air-blower');

        $alts = $res->json('image_alts');
        $this->assertSame('Electric Blower 600W', $alts['https://cdn.example.com/thumb.png']);
        $this->assertCount(3, $alts);

        $head = $res->json('head_html');
        $this->assertStringContainsString('"@type":"Product"', $head);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $head);
        $this->assertStringContainsString('"@type":"Offer"', $head);
        $this->assertStringNotContainsString('FAQPage', $head);
    }

    public function test_long_description_is_truncated_to_160(): void
    {
        $product = $this->product(['description' => str_repeat('Heavy duty tool for workshops. ', 20)]);

        $desc = $this->seo('/products/'.$product->id)->assertOk()->json('description');
        $this->assertLessThanOrEqual(160, mb_strlen($desc));
        $this->assertStringEndsWith('…', $desc);
    }

    public function test_product_urls_are_id_only_and_normalised(): void
    {
        $product = $this->product();

        $this->seo('/products/'.$product->slug)->assertNotFound()->assertJsonPath('robots', 'noindex,follow');
        $this->seo('/products/0')->assertNotFound();
        $this->seo('/products/'.str_pad((string) $product->id, 4, '0', STR_PAD_LEFT))->assertOk()
            ->assertJsonPath('canonical_url', self::SHOP.'/products/'.$product->id);
    }

    public function test_static_page_and_home_defaults(): void
    {
        $this->seo('/about')->assertOk()
            ->assertJsonPath('type', 'page')
            ->assertJsonPath('title', 'About Us | OPEL Tools')
            ->assertJsonPath('canonical_url', self::SHOP.'/about');

        $home = $this->seo('/')->assertOk()->assertJsonPath('canonical_url', self::SHOP.'/');
        $this->assertStringContainsString('"@type":"WebSite"', $home->json('head_html'));

        // Query strings and trailing slashes normalise to the same page.
        $this->seo('/about/?utm=x')->assertOk()->assertJsonPath('canonical_url', self::SHOP.'/about');
    }

    public function test_category_group_and_category_paths(): void
    {
        $this->product();

        $this->seo('/category/power-tools')->assertOk()
            ->assertJsonPath('type', 'category_group')
            ->assertJsonPath('title', 'Power Tools | OPEL Tools');

        $this->seo('/category/power-tools/air-blower')->assertOk()
            ->assertJsonPath('type', 'category')
            ->assertJsonPath('h1', 'Air Blower')
            ->assertJsonPath('canonical_url', self::SHOP.'/category/power-tools/air-blower');

        $this->seo('/category/power-tools/missing')->assertNotFound()->assertJsonPath('robots', 'noindex,follow');
    }

    // ---- private / unpublished -------------------------------------------

    public function test_private_routes_forced_noindex(): void
    {
        foreach (['/cart', '/checkout', '/checkout/payment/return', '/profile/orders/5', '/orders', '/login', '/wishlist', '/CART'] as $path) {
            $res = $this->seo($path)->assertOk()
                ->assertJsonPath('type', 'private')
                ->assertJsonPath('robots', 'noindex,nofollow')
                ->assertJsonPath('canonical_url', null);
            $this->assertStringNotContainsString('ld+json', $res->json('head_html'), $path);
        }
    }

    public function test_unpublished_and_unapproved_products_are_404_noindex_without_leaking_overrides(): void
    {
        $unpublished = $this->product(['published' => false]);
        SeoMeta::query()->create([
            'target_type' => 'product', 'target_key' => (string) $unpublished->id,
            'seo_title' => 'SECRET TITLE', 'robots' => 'index',
        ]);

        $res = $this->seo('/products/'.$unpublished->id)->assertNotFound()
            ->assertJsonPath('robots', 'noindex,follow')
            ->assertJsonPath('canonical_url', null);
        $this->assertStringNotContainsString('SECRET TITLE', $res->getContent());

        $unapproved = Product::factory()->unapproved()->create(['category_id' => $unpublished->category_id]);
        $this->seo('/products/'.$unapproved->id)->assertNotFound()->assertJsonPath('robots', 'noindex,follow');
    }

    public function test_unknown_paths_404_and_are_not_cached(): void
    {
        $this->seo('/totally/unknown')->assertNotFound()->assertJsonPath('status', 404);
        $this->seo('/'.str_repeat('a', 600))->assertNotFound();
    }

    // ---- escaping / XSS --------------------------------------------------

    public function test_head_html_escapes_all_values(): void
    {
        $product = $this->product(['name' => 'Drill </script><script>alert(1)</script>']);
        SeoMeta::query()->create([
            'target_type' => 'product',
            'target_key' => (string) $product->id,
            'seo_title' => '"><script>alert("t")</script>',
            'meta_description' => 'desc" onload="alert(1)',
            'og_title' => '<img src=x onerror=alert(1)>',
            'faq' => [['q' => 'Q </script><script>alert(2)</script>', 'a' => 'A & <b>']],
        ]);

        $head = $this->seo('/products/'.$product->id)->assertOk()->json('head_html');

        $this->assertStringNotContainsString('<script>alert', $head);
        $this->assertStringNotContainsString('<img', $head);
        $this->assertStringNotContainsString('onload="alert', $head);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $head);
        // Only our own ld+json script tags exist: opening count == closing count == ld blocks.
        $ld = substr_count($head, '<script data-seo type="application/ld+json">');
        $this->assertSame($ld, substr_count($head, '</script>'));
        $this->assertSame($ld, substr_count($head, '<script'));
        $this->assertStringContainsString('\u003C/script\u003E', $head);
        $this->assertStringContainsString('"@type":"FAQPage"', $head);
    }

    public function test_content_is_sanitized_on_save_and_output(): void
    {
        $product = $this->product();

        $this->saveSeo("/api/v1/admin/seo/product/{$product->id}", $this->validBody([
            'content' => '<h1>Top</h1><h2 onclick="x()">Why</h2><p>Hi<script>alert(1)</script></p><a href="javascript:alert(1)">x</a><iframe src="//evil"></iframe>',
        ]))->assertOk()
            ->assertJsonPath('meta.content', '<h2>Top</h2><h2>Why</h2><p>Hi</p>x');

        // Even a raw DB write is re-sanitized on the public endpoint.
        SeoMeta::query()->where('target_key', (string) $product->id)->update(['content' => '<p>ok</p><script>bad()</script>']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->seo('/products/'.$product->id)->assertJsonPath('content', '<p>ok</p>');
    }

    // ---- JSON-LD -------------------------------------------------------------

    public function test_jsonld_override_validation(): void
    {
        $product = $this->product();
        $url = "/api/v1/admin/seo/product/{$product->id}";

        $this->saveSeo($url, $this->validBody(['schema_jsonld' => '{not json']))
            ->assertUnprocessable()->assertJsonValidationErrors('schema_jsonld');
        $this->saveSeo($url, $this->validBody(['schema_jsonld' => '"just a string"']))
            ->assertUnprocessable()->assertJsonValidationErrors('schema_jsonld');
        $this->saveSeo($url, $this->validBody(['schema_jsonld' => '{"name":"no type"}']))
            ->assertUnprocessable()->assertJsonValidationErrors('schema_jsonld');
        $this->saveSeo($url, $this->validBody(['schema_jsonld' => '[{"@type":"Thing"}, 5]']))
            ->assertUnprocessable()->assertJsonValidationErrors('schema_jsonld');
    }

    public function test_jsonld_override_replaces_same_type_and_augments_others(): void
    {
        $product = $this->product();

        $this->saveSeo("/api/v1/admin/seo/product/{$product->id}", $this->validBody([
            'schema_jsonld' => json_encode([
                ['@type' => 'Product', 'name' => 'Override </script> name'],
                ['@type' => 'HowTo', 'name' => 'Use it'],
            ]),
        ]))->assertOk();

        $head = $this->seo('/products/'.$product->id)->json('head_html');
        $this->assertSame(1, substr_count($head, '"@type":"Product"'));
        $this->assertStringContainsString('Override \u003C/script\u003E name', $head);
        $this->assertStringNotContainsString('"@type":"Offer"', $head); // auto Product replaced
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $head); // auto kept
        $this->assertStringContainsString('"@type":"HowTo"', $head); // augmented
    }

    // ---- sitemap / robots ----------------------------------------------------

    public function test_sitemap_contains_only_indexable_urls(): void
    {
        $published = $this->product();
        $unpublished = Product::factory()->unpublished()->create(['category_id' => $published->category_id]);
        $unapproved = Product::factory()->unapproved()->create(['category_id' => $published->category_id]);
        $noindexed = Product::factory()->create(['category_id' => $published->category_id]);
        SeoMeta::query()->create(['target_type' => 'product', 'target_key' => (string) $noindexed->id, 'robots' => 'noindex']);
        $inactive = Category::factory()->create(['slug' => 'hidden-cat', 'is_active' => false]);

        $res = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', $res->headers->get('Content-Type'));
        $xml = $res->getContent();

        $this->assertStringContainsString('<loc>'.self::SHOP.'/</loc>', $xml);
        $this->assertStringContainsString('<loc>'.self::SHOP.'/about</loc>', $xml);
        $this->assertStringContainsString('<loc>'.self::SHOP.'/products</loc>', $xml);
        $this->assertStringNotContainsString('/category/', $xml); // SEO_SITEMAP_CATEGORIES defaults to false
        $this->assertStringContainsString('<loc>'.self::SHOP.'/products/'.$published->id.'</loc>', $xml);
        $this->assertStringContainsString('<lastmod>', $xml);

        foreach ([$unpublished, $unapproved, $noindexed] as $hidden) {
            $this->assertStringNotContainsString('/products/'.$hidden->id.'<', $xml);
        }
        $this->assertStringNotContainsString('hidden-cat', $xml);

        config(['seo.sitemap_categories' => true]);
        $xml = $this->get('/sitemap.xml')->getContent();
        $this->assertStringContainsString('<loc>'.self::SHOP.'/category/power-tools</loc>', $xml);
        $this->assertStringContainsString('<loc>'.self::SHOP.'/category/power-tools/air-blower</loc>', $xml);
        $this->assertStringNotContainsString('hidden-cat', $xml);
        foreach (['/cart', '/checkout', '/profile', '/orders', '/login', '/wishlist'] as $private) {
            $this->assertStringNotContainsString(self::SHOP.$private.'<', $xml);
        }
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_robots_txt(): void
    {
        $res = $this->get('/robots.txt')->assertOk();
        $this->assertStringContainsString('text/plain', $res->headers->get('Content-Type'));
        $body = $res->getContent();

        foreach (['/cart', '/checkout', '/profile', '/orders', '/login', '/wishlist'] as $private) {
            $this->assertStringContainsString("Disallow: {$private}\n", $body);
        }
        $this->assertStringContainsString('Sitemap: '.self::SHOP.'/sitemap.xml', $body);
        $this->assertNull($res->headers->get('Set-Cookie'));
    }

    // ---- admin ---------------------------------------------------------------

    public function test_admin_routes_require_staff_token(): void
    {
        $product = $this->product();
        $shopperToken = User::factory()->create()->createToken('opel-b2c')->plainTextToken;

        $this->getJson('/api/v1/admin/seo/targets')->assertUnauthorized();
        $this->getJson('/api/v1/admin/seo/page/home')->assertUnauthorized();
        $this->putJson('/api/v1/admin/seo/page/home', $this->validBody())->assertUnauthorized();

        foreach ([
            ['get', '/api/v1/admin/seo/targets?type=products'],
            ['get', "/api/v1/admin/seo/product/{$product->id}"],
            ['put', "/api/v1/admin/seo/product/{$product->id}"],
        ] as [$method, $url]) {
            $this->app['auth']->forgetGuards();
            $this->withToken($shopperToken)->json($method, $url, $this->validBody())->assertForbidden();
        }

        $this->assertSame(0, SeoMeta::query()->count());
    }

    public function test_admin_lists_targets_by_type_with_search(): void
    {
        $product = $this->product();
        SeoMeta::query()->create(['target_type' => 'page', 'target_key' => 'about', 'seo_title' => 'About OPEL']);

        $this->asAdmin()->getJson('/api/v1/admin/seo/targets?type=pages')->assertOk()
            ->assertJsonPath('meta.total', count(config('seo.pages')))
            ->assertJsonStructure(['data' => [['type', 'key', 'name', 'path', 'url', 'has_meta', 'robots']]]);

        $this->asAdmin()->getJson('/api/v1/admin/seo/targets?type=pages&q=about')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.has_meta', true)
            ->assertJsonPath('data.0.seo_title', 'About OPEL');

        $this->asAdmin()->getJson('/api/v1/admin/seo/targets?type=products&q=blower')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', (string) $product->id)
            ->assertJsonPath('data.0.path', '/products/'.$product->id);

        $this->asAdmin()->getJson('/api/v1/admin/seo/targets?type=categories&q=air')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'category');

        $this->asAdmin()->getJson('/api/v1/admin/seo/targets?type=bogus')->assertUnprocessable();
        $this->asAdmin()->getJson('/api/v1/admin/seo/page/nope')->assertNotFound();
        $this->asAdmin()->getJson('/api/v1/admin/seo/product/999999')->assertNotFound();
    }

    public function test_admin_save_round_trip_and_public_payload(): void
    {
        $product = $this->product();
        $related = Product::factory()->create(['category_id' => $product->category_id, 'name' => 'Related Drill']);

        // Prime the public cache so we prove the save busts it.
        $this->seo('/products/'.$product->id)->assertJsonPath('title', 'Electric Blower 600W | OPEL Tools');

        $this->saveSeo("/api/v1/admin/seo/product/{$product->id}", [
            'seo_title' => 'Best 600W Blower',
            'meta_description' => 'Buy the best blower.',
            'h1' => 'OPEL 600W Electric Blower',
            'primary_keyword' => 'electric blower',
            'secondary_keywords' => ['air blower', 'Air Blower', ' dust blower '],
            'canonical_url' => 'https://shop.example.com/products/'.$product->id,
            'robots' => 'index',
            'og_image' => 'https://cdn.example.com/og.png',
            'faq' => [['q' => 'Warranty?', 'a' => '6 months.']],
            'related_product_ids' => [$related->id],
            'image_alts' => [['url' => 'https://cdn.example.com/a.png', 'alt' => 'Side view']],
        ])->assertOk()
            ->assertJsonPath('meta.secondary_keywords', ['air blower', 'dust blower'])
            ->assertJsonPath('meta.related_products.0.name', 'Related Drill')
            ->assertJsonPath('preview.title', 'Best 600W Blower');

        $res = $this->seo('/products/'.$product->id)->assertOk()
            ->assertJsonPath('title', 'Best 600W Blower')
            ->assertJsonPath('h1', 'OPEL 600W Electric Blower')
            ->assertJsonPath('h1_is_override', true)
            ->assertJsonPath('og.image', 'https://cdn.example.com/og.png')
            ->assertJsonPath('faq.0.q', 'Warranty?')
            ->assertJsonPath('related_products.0.id', $related->id);
        $this->assertSame('Side view', $res->json('image_alts')['https://cdn.example.com/a.png']);

        // Internal fields never leak publicly.
        $json = $res->json();
        $this->assertArrayNotHasKey('primary_keyword', $json);
        $this->assertArrayNotHasKey('secondary_keywords', $json);
        $this->assertArrayNotHasKey('schema_jsonld', $json);
        $this->assertStringContainsString('"@type":"FAQPage"', $json['head_html']);
    }

    public function test_admin_save_validation(): void
    {
        $product = $this->product();
        $other = Product::factory()->create(['category_id' => $product->category_id]);
        $url = "/api/v1/admin/seo/product/{$product->id}";

        $cases = [
            'seo_title' => ['seo_title' => str_repeat('a', 91)],
            'meta_description' => ['meta_description' => str_repeat('a', 321)],
            'robots' => ['robots' => 'nofollow'],
            'faq.0.a' => ['faq' => [['q' => 'Q?']]],
        ];
        foreach ($cases as $field => $body) {
            $this->saveSeo($url, $this->validBody($body))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        foreach (['javascript:alert(1)', 'ftp://x.com/a', '/relative/path', '//evil.com/x'] as $bad) {
            $this->saveSeo($url, $this->validBody(['canonical_url' => $bad]))
                ->assertUnprocessable()->assertJsonValidationErrors('canonical_url');
            $this->saveSeo($url, $this->validBody(['og_image' => $bad]))
                ->assertUnprocessable()->assertJsonValidationErrors('og_image');
        }

        $this->saveSeo($url, $this->validBody(['related_product_ids' => [$product->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('related_product_ids');
        $this->saveSeo($url, $this->validBody(['related_product_ids' => [999999]]))
            ->assertUnprocessable()->assertJsonValidationErrors('related_product_ids');
        $this->saveSeo($url, $this->validBody([
            'image_alts' => [['url' => 'https://evil.example/x.png', 'alt' => 'nope']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('image_alts');

        // Image alts on a non-product target are rejected.
        $this->saveSeo('/api/v1/admin/seo/page/about', $this->validBody([
            'image_alts' => [['url' => 'https://cdn.example.com/a.png', 'alt' => 'x']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('image_alts');

        $this->assertSame(0, SeoMeta::query()->count());
        unset($other);
    }

    public function test_admin_noindex_override_is_respected_publicly(): void
    {
        $this->saveSeo('/api/v1/admin/seo/page/careers', $this->validBody(['robots' => 'noindex']))->assertOk();

        $this->seo('/careers')->assertOk()->assertJsonPath('robots', 'noindex,follow');
        $this->assertStringNotContainsString(self::SHOP.'/careers<', $this->get('/sitemap.xml')->getContent());
    }

    // ---- review fixes ----------------------------------------------------------

    public function test_canonical_must_be_on_storefront_host(): void
    {
        $product = $this->product();
        $url = "/api/v1/admin/seo/product/{$product->id}";

        foreach ([
            'https://evil.example.net/products/1',
            'https://shop.example.com.evil.net/x',
            'http://api.example.com/products/1',
            'http://shop.example.com/products/1',          // scheme mismatch
            'https://shop.example.com:8443/products/1',    // port mismatch
            'https://user@shop.example.com/products/1',    // userinfo
            'https://user:pw@shop.example.com/products/1', // userinfo
            'https://shop.example.com@evil.example.net/x', // userinfo → foreign host
        ] as $foreign) {
            $this->saveSeo($url, $this->validBody(['canonical_url' => $foreign]))
                ->assertUnprocessable()->assertJsonValidationErrors('canonical_url');
        }
        $this->saveSeo($url, $this->validBody(['canonical_url' => 'https://SHOP.example.com/products/'.$product->id]))
            ->assertOk();
        $this->saveSeo($url, $this->validBody(['canonical_url' => 'https://shop.example.com:443/products/'.$product->id]))
            ->assertOk(); // explicit default port == implicit

        // Legacy rows: scheme/port/userinfo mismatches also fall back to self.
        foreach (['http://shop.example.com/x', 'https://shop.example.com:8443/x', 'https://u:p@shop.example.com/x'] as $legacy) {
            SeoMeta::query()->where('target_key', (string) $product->id)->update(['canonical_url' => $legacy]);
            \Illuminate\Support\Facades\Cache::flush();
            $this->seo('/products/'.$product->id)->assertJsonPath('canonical_url', self::SHOP.'/products/'.$product->id);
        }

        // Legacy row with a foreign canonical: public payload + sitemap fall back to self.
        SeoMeta::query()->where('target_key', (string) $product->id)->update(['canonical_url' => 'https://evil.example.net/x']);
        \Illuminate\Support\Facades\Cache::flush();
        $res = $this->seo('/products/'.$product->id)->assertOk()
            ->assertJsonPath('canonical_url', self::SHOP.'/products/'.$product->id)
            ->assertJsonPath('og.url', self::SHOP.'/products/'.$product->id);
        $this->assertStringNotContainsString('evil.example.net', $res->getContent());
        $this->assertStringContainsString('<loc>'.self::SHOP.'/products/'.$product->id.'</loc>', $this->get('/sitemap.xml')->getContent());
    }

    public function test_cache_is_keyed_by_resolved_target_not_raw_path(): void
    {
        $product = $this->product();
        \Illuminate\Support\Facades\Cache::flush();

        foreach (['/about', '/ABOUT', '/about/', '//about?x=1'] as $p) {
            $this->seo($p)->assertOk()->assertJsonPath('type', 'page');
        }
        foreach (['/products/'.$product->id, '/products/00'.$product->id, '/PRODUCTS/'.$product->id] as $p) {
            $this->seo($p)->assertOk()->assertJsonPath('type', 'product');
        }
        foreach (['/cart', '/cart/abc', '/cart/'.uniqid(), '/checkout/payment', '/PROFILE/orders/9'] as $p) {
            $this->seo($p)->assertOk()->assertJsonPath('type', 'private')->assertJsonPath('path', null);
        }
        foreach (['/nope', '/products/abc', '/products/999999', '/category/x/y'] as $p) {
            $this->seo($p)->assertNotFound();
        }

        $keys = $this->seoCacheKeys();
        sort($keys);
        $v = \App\Modules\Seo\Support\SeoCache::version();
        $this->assertSame([
            "seo:payload:v{$v}:page:about",
            "seo:payload:v{$v}:private:private",
            "seo:payload:v{$v}:product:{$product->id}",
        ], $keys);
    }

    public function test_category_must_belong_to_group_in_url(): void
    {
        $this->product();
        CategoryGroup::factory()->create(['name' => 'HAND TOOLS', 'slug' => 'hand-tools']);

        $this->seo('/category/power-tools/air-blower')->assertOk()->assertJsonPath('type', 'category');
        $this->seo('/category/hand-tools/air-blower')->assertNotFound()->assertJsonPath('robots', 'noindex,follow');
        $this->seo('/category/nope/air-blower')->assertNotFound();
    }

    public function test_edge_key_gets_separate_rate_limit_bucket(): void
    {
        config(['seo.rate_limit' => 2, 'seo.edge_rate_limit' => 5, 'seo.edge_key' => 'edge-secret']);
        $uri = '/api/v1/seo?path=%2Fabout';

        $this->getJson($uri)->assertOk();
        $this->getJson($uri)->assertOk();
        $this->getJson($uri)->assertStatus(429);
        $this->getJson($uri, ['X-Seo-Edge-Key' => 'wrong'])->assertStatus(429); // wrong key → IP bucket

        for ($i = 0; $i < 5; $i++) {
            $this->getJson($uri, ['X-Seo-Edge-Key' => 'edge-secret'])->assertOk();
        }
        $this->getJson($uri, ['X-Seo-Edge-Key' => 'edge-secret'])->assertStatus(429);

        // Key unset on the BE: header is ignored, IP limiter applies.
        config(['seo.edge_key' => null]);
        $this->getJson($uri, ['X-Seo-Edge-Key' => ''])->assertStatus(429);
    }

    public function test_faqpage_is_rejected_in_jsonld_override(): void
    {
        $product = $this->product();
        $url = "/api/v1/admin/seo/product/{$product->id}";

        foreach ([
            '{"@type":"FAQPage","mainEntity":[]}',
            '[{"@type":"HowTo"},{"@type":"FAQPage"}]',
            '{"@type":["WebPage","FAQPage"]}',
            '{"@type":"WebPage","@graph":[{"@type":"https://schema.org/FAQPage"}]}',
            '{"@type":"WebPage","mainEntity":{"@type":"faqpage"}}',
        ] as $schema) {
            $res = $this->saveSeo($url, $this->validBody(['schema_jsonld' => $schema]))
                ->assertUnprocessable()->assertJsonValidationErrors('schema_jsonld');
            $this->assertStringContainsString('FAQ field', $res->json('errors.schema_jsonld.0'), $schema);
        }

        // Too deep to inspect → rejected (fail closed), not silently accepted.
        $deep = ['@type' => 'WebPage'];
        $cursor = &$deep;
        for ($i = 0; $i < 40; $i++) {
            $cursor['x'] = ['@type' => 'Thing'];
            $cursor = &$cursor['x'];
        }
        unset($cursor);
        $this->saveSeo($url, $this->validBody(['schema_jsonld' => json_encode($deep)]))
            ->assertUnprocessable()->assertJsonValidationErrors('schema_jsonld');
        $this->assertTrue(\App\Modules\Seo\Services\SeoResolver::containsType($deep, 'FAQPage'));
        $this->assertFalse(\App\Modules\Seo\Services\SeoResolver::containsType(['@type' => 'WebPage', 'a' => ['@type' => 'Thing']], 'FAQPage'));

        // Legacy row containing FAQPage: dropped on output, other blocks kept.
        SeoMeta::query()->create([
            'target_type' => 'product', 'target_key' => (string) $product->id,
            'schema_jsonld' => '[{"@type":"FAQPage","mainEntity":[]},{"@type":"HowTo","name":"Use"}]',
        ]);
        $head = $this->seo('/products/'.$product->id)->json('head_html');
        $this->assertStringNotContainsString('FAQPage', $head);
        $this->assertStringContainsString('"@type":"HowTo"', $head);
    }

    public function test_indexing_switch_off_blocks_crawlers(): void
    {
        config(['seo.allow_indexing' => false]);

        $this->assertSame("User-agent: *\nDisallow: /\n", $this->get('/robots.txt')->assertOk()->getContent());
        $this->seo('/about')->assertOk()->assertJsonPath('robots', 'noindex,nofollow');
    }

    public function test_explicit_empty_clears_and_omitted_key_preserves(): void
    {
        $product = $this->product();
        $related = Product::factory()->create(['category_id' => $product->category_id]);
        $url = "/api/v1/admin/seo/product/{$product->id}";

        $this->saveSeo($url, [
            'h1' => 'Hello!', 'seo_title' => 'T', 'content' => '<p>x</p>',
            'faq' => [['q' => 'Q', 'a' => 'A']], 'related_product_ids' => [$related->id],
            'secondary_keywords' => ['drill'],
        ])->assertOk();

        // Omitted keys keep their stored values.
        $this->saveSeo($url, ['content' => '<p>y</p>'])->assertOk()
            ->assertJsonPath('meta.h1', 'Hello!')
            ->assertJsonPath('meta.seo_title', 'T')
            ->assertJsonPath('meta.content', '<p>y</p>')
            ->assertJsonCount(1, 'meta.faq')
            ->assertJsonCount(1, 'meta.related_products');

        // Explicit "" / null / [] clear.
        $this->saveSeo($url, [
            'h1' => '', 'seo_title' => null, 'faq' => [], 'related_product_ids' => [], 'secondary_keywords' => [],
        ])->assertOk()
            ->assertJsonPath('meta.h1', null)
            ->assertJsonPath('meta.seo_title', null)
            ->assertJsonPath('meta.content', '<p>y</p>')
            ->assertJsonPath('meta.faq', [])
            ->assertJsonPath('meta.related_products', [])
            ->assertJsonPath('meta.secondary_keywords', []);
        $this->seo('/products/'.$product->id)->assertJsonPath('h1_is_override', false);
    }

    public function test_version_guard_rejects_stale_saves(): void
    {
        $product = $this->product();
        $url = "/api/v1/admin/seo/product/{$product->id}";

        $this->asAdmin()->getJson($url)->assertOk()->assertJsonPath('meta.version', 0);

        // Form A (loaded v0) creates the row → v1.
        $this->asAdmin()->putJson($url, $this->validBody(['h1' => 'Hello!', 'expected_version' => 0]))
            ->assertOk()->assertJsonPath('meta.version', 1);

        // Same second, no time travel: stale v0 form is rejected; nothing written.
        $this->asAdmin()->putJson($url, $this->validBody(['h1' => null, 'content' => '<p>stale</p>', 'expected_version' => 0]))
            ->assertStatus(409)
            ->assertJsonPath('current.meta.h1', 'Hello!')
            ->assertJsonPath('current.meta.version', 1);

        // Up-to-date form → v2; even a no-op save bumps the version.
        $this->asAdmin()->putJson($url, $this->validBody(['h1' => 'Hello!', 'expected_version' => 1]))
            ->assertOk()->assertJsonPath('meta.version', 2);
        $this->asAdmin()->putJson($url, $this->validBody(['h1' => null, 'expected_version' => 1]))->assertStatus(409);
        $this->asAdmin()->putJson($url, $this->validBody(['expected_version' => 99]))->assertStatus(409);
        $this->asAdmin()->putJson($url, $this->validBody(['expected_version' => 'x']))
            ->assertUnprocessable()->assertJsonValidationErrors('expected_version');

        $row = SeoMeta::query()->sole();
        $this->assertSame('Hello!', $row->h1);
        $this->assertNull($row->content);
        $this->assertSame(2, $row->version);
    }

    public function test_row_created_after_form_loaded_is_a_conflict(): void
    {
        $product = $this->product();
        $url = "/api/v1/admin/seo/product/{$product->id}";

        // Two forms load while no row exists (version 0 / null). Form A saves first.
        $this->asAdmin()->putJson($url, $this->validBody(['h1' => 'From A', 'expected_version' => 0]))
            ->assertOk()->assertJsonPath('meta.version', 1);

        // Form B also thinks "no row yet" → 409, A's row untouched.
        foreach ([0] as $expected) {
            $this->asAdmin()->putJson($url, $this->validBody(['h1' => 'From B', 'expected_version' => $expected]))
                ->assertStatus(409)->assertJsonPath('current.meta.h1', 'From A');
        }
        $this->assertSame(1, SeoMeta::query()->count());
        $this->assertSame('From A', SeoMeta::query()->value('h1'));
    }

    public function test_expected_version_is_required(): void
    {
        $product = $this->product();
        $url = "/api/v1/admin/seo/product/{$product->id}";

        foreach ([[], ['expected_version' => null], ['expected_version' => -1], ['expected_version' => 'x']] as $extra) {
            $this->asAdmin()->putJson($url, $this->validBody(['h1' => 'Nope']) + $extra)
                ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        }
        $this->assertSame(0, SeoMeta::query()->count());
    }

    public function test_stale_update_affects_no_rows_and_keeps_values(): void
    {
        $product = $this->product();
        $url = "/api/v1/admin/seo/product/{$product->id}";
        $this->saveSeo($url, ['h1' => 'v1 h1', 'content' => '<p>v1</p>'])->assertJsonPath('meta.version', 1);

        // Another writer bumps the row behind the form's back.
        SeoMeta::query()->update(['version' => 5]);

        $this->asAdmin()->putJson($url, ['h1' => 'stale', 'expected_version' => 1])
            ->assertStatus(409)->assertJsonPath('current.meta.version', 5)->assertJsonPath('current.meta.h1', 'v1 h1');
        $this->asAdmin()->putJson($url, ['h1' => 'fresh', 'expected_version' => 5])
            ->assertOk()->assertJsonPath('meta.version', 6)->assertJsonPath('meta.h1', 'fresh')
            ->assertJsonPath('meta.content', '<p>v1</p>'); // omitted key preserved
    }

    public function test_admin_saves_invalidate_public_cache_on_create_and_update_only(): void
    {
        $product = $this->product();
        $url = "/api/v1/admin/seo/product/{$product->id}";
        $path = '/products/'.$product->id;
        $cache = \App\Modules\Seo\Support\SeoCache::class;

        // Prime the cache with defaults.
        $this->seo($path)->assertOk()->assertJsonPath('h1_is_override', false)
            ->assertJsonPath('title', 'Electric Blower 600W | OPEL Tools');
        $this->assertNotEmpty($this->seoCacheKeys());

        // Create path (expected_version 0).
        $this->asAdmin()->putJson($url, $this->validBody(['seo_title' => 'Created title', 'h1' => 'Created H1', 'expected_version' => 0]))
            ->assertOk()->assertJsonPath('meta.version', 1);
        $this->seo($path)->assertJsonPath('title', 'Created title')->assertJsonPath('h1', 'Created H1')
            ->assertJsonPath('h1_is_override', true);
        $createdAt = SeoMeta::query()->value('updated_at');

        // Update path (raw conditional UPDATE): cache bumped + updated_at moves.
        $this->travel(5)->seconds();
        $this->asAdmin()->putJson($url, $this->validBody(['seo_title' => 'Updated title', 'h1' => 'Updated H1', 'expected_version' => 1]))
            ->assertOk()->assertJsonPath('meta.version', 2);
        $this->seo($path)->assertJsonPath('title', 'Updated title')->assertJsonPath('h1', 'Updated H1');
        $this->assertTrue(SeoMeta::query()->sole()->updated_at->gt($createdAt), 'updated_at set by UPDATE');

        // 409 (stale update and duplicate create): no write, no cache bump.
        $version = $cache::version();
        $this->asAdmin()->putJson($url, $this->validBody(['seo_title' => 'Stale', 'expected_version' => 1]))->assertStatus(409);
        $this->asAdmin()->putJson($url, $this->validBody(['seo_title' => 'Dup', 'expected_version' => 0]))->assertStatus(409);
        $this->assertSame($version, $cache::version());
        $this->seo($path)->assertJsonPath('title', 'Updated title');
    }

    /** @return list<string> */
    private function seoCacheKeys(): array
    {
        $store = \Illuminate\Support\Facades\Cache::store()->getStore();
        $prop = new \ReflectionProperty($store, 'storage');

        return array_values(array_filter(
            array_keys($prop->getValue($store)),
            fn (string $k): bool => str_starts_with($k, \App\Modules\Seo\Support\SeoCache::PREFIX),
        ));
    }
}
