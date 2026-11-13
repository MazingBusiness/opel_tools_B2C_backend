<?php

namespace App\Modules\Admin\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Whole-layout save. Image URLs must be https; links must be an internal path
 * ("/...", not "//...") or an https URL. Type-specific fields are excluded for
 * other types, so validated() only carries what the section type uses.
 */
class UpdateAdminHomeRequest extends FormRequest
{
    public const TONES = ['muted', 'sand', 'brand', 'ink', 'surface'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $image = ['required', 'string', 'max:2048', $this->httpsUrl()];
        $link = ['string', 'max:2048', $this->safeLink()];
        $text = fn (int $max): array => ['nullable', 'string', 'max:'.$max];

        $hero = 'exclude_unless:sections.*.type,hero';
        $band = 'exclude_unless:sections.*.type,category_band';
        $row = 'exclude_unless:sections.*.type,banner_row';

        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'sections' => ['present', 'array', 'max:30'],
            'sections.*' => ['array'],
            'sections.*.id' => ['required', 'string', 'regex:/^[a-z0-9-]{1,40}$/', 'distinct'],
            'sections.*.type' => ['required', Rule::in(['hero', 'category_band', 'banner_row'])],
            'sections.*.hidden' => ['sometimes', 'boolean'],

            'sections.*.slides' => [$hero, 'required', 'array', 'min:1', 'max:10'],
            'sections.*.slides.*.image_url' => [$hero, ...$image],
            'sections.*.slides.*.link_url' => [$hero, 'nullable', ...$link],

            'sections.*.group_id' => [$band, 'required', 'integer', 'exists:category_groups,id'],
            'sections.*.title' => [$band, ...$text(80)],
            'sections.*.product_limit' => [$band, 'nullable', 'integer', 'min:4', 'max:24'],
            'sections.*.pinned_part_nos' => [$band, 'nullable', 'array', 'max:24'],
            'sections.*.pinned_part_nos.*' => [$band, 'string', 'max:64'],

            'sections.*.banners' => [$row, 'required', 'array', 'min:1', 'max:4'],
            'sections.*.banners.*.title' => [$row, 'required', 'string', 'max:120'],
            'sections.*.banners.*.eyebrow' => [$row, ...$text(60)],
            'sections.*.banners.*.subtitle' => [$row, ...$text(200)],
            'sections.*.banners.*.badge' => [$row, ...$text(40)],
            'sections.*.banners.*.cta_label' => [$row, ...$text(40)],
            'sections.*.banners.*.href' => [$row, 'required', ...$link],
            'sections.*.banners.*.image_url' => [$row, ...$image],
            'sections.*.banners.*.tone' => [$row, 'nullable', Rule::in(self::TONES)],
        ];
    }

    private function httpsUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isHttpsUrl($value)) {
                $fail('Must be an https:// URL.');
            }
        };
    }

    private function safeLink(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isSafeLink($value)) {
                $fail('Must be a site path starting with "/" or an https:// URL.');
            }
        };
    }

    public static function isHttpsUrl(string $value): bool
    {
        if (preg_match('/[\s\x00-\x1F\x7F\\\\]/', $value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($value, PHP_URL_HOST) !== '';
    }

    public static function isSafeLink(string $value): bool
    {
        if (preg_match('/[\s\x00-\x1F\x7F\\\\]/', $value)) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//');
        }

        return self::isHttpsUrl($value);
    }
}
