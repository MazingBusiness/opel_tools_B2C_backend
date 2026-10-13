<?php

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Seo\Services\SeoResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Field-level validation. Target-dependent checks (image keys belong to the
 * product, related ids exist and aren't self) run in AdminSeoController.
 */
class UpdateAdminSeoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trim = [];
        foreach ([
            'seo_title', 'meta_description', 'h1', 'primary_keyword', 'canonical_url',
            'og_title', 'og_description', 'og_image', 'schema_jsonld', 'content',
        ] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $trim[$field] = $value === '' ? null : $value;
            }
        }
        $this->merge($trim);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // seo_meta.version the form was loaded with (0 = no row yet). Mismatch → 409.
            'expected_version' => ['required', 'integer', 'min:0'],
            'seo_title' => ['nullable', 'string', 'max:90'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'h1' => ['nullable', 'string', 'max:150'],
            'content' => ['nullable', 'string', 'max:50000'],
            'primary_keyword' => ['nullable', 'string', 'max:100'],
            'secondary_keywords' => ['nullable', 'array', 'max:20'],
            'secondary_keywords.*' => ['string', 'max:100'],
            'canonical_url' => ['nullable', 'string', 'max:2048', 'url:http,https', $this->absoluteHttp(), $this->storefrontHost()],
            'robots' => ['nullable', Rule::in(['index', 'noindex'])],
            'og_title' => ['nullable', 'string', 'max:90'],
            'og_description' => ['nullable', 'string', 'max:320'],
            'og_image' => ['nullable', 'string', 'max:2048', 'url:http,https', $this->absoluteHttp()],
            'schema_jsonld' => ['nullable', 'string', 'max:20000', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ($error = SeoResolver::validateJsonLd($value)) !== null) {
                    $fail($error);
                }
            }],
            'faq' => ['nullable', 'array', 'max:20'],
            'faq.*' => ['array:q,a'],
            'faq.*.q' => ['required', 'string', 'max:300'],
            'faq.*.a' => ['required', 'string', 'max:2000'],
            'related_product_ids' => ['nullable', 'array', 'max:12'],
            'related_product_ids.*' => ['integer', 'min:1', 'distinct'],
            // List of {url, alt} (URL keys contain dots, which break dot-notation rules).
            'image_alts' => ['nullable', 'array', 'list', 'max:50'],
            'image_alts.*' => ['array:url,alt'],
            'image_alts.*.url' => ['required', 'string', 'max:2048'],
            'image_alts.*.alt' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'canonical_url.url' => 'Canonical URL must be an absolute http(s) URL.',
            'og_image.url' => 'OG image must be an absolute http(s) URL.',
        ];
    }

    /** Canonical must point at the storefront itself (STOREFRONT_URL host). */
    private function storefrontHost(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $seo = app(SeoResolver::class);
            if (is_string($value) && $seo->absoluteHttpUrl($value) !== null && ! $seo->isStorefrontUrl($value)) {
                $fail('Canonical URL must be on the storefront origin '.rtrim((string) config('seo.storefront_url'), '/').' (same scheme, host and port, no user/password).');
            }
        };
    }

    private function absoluteHttp(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && app(SeoResolver::class)->absoluteHttpUrl($value) === null) {
                $fail('The '.str_replace('_', ' ', $attribute).' must be an absolute http(s) URL.');
            }
        };
    }
}
