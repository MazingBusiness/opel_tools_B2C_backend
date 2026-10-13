{{-- OPEL SEO head fragment. Every value is escaped by {{ }}; JSON-LD blocks are
     pre-encoded with JSON_HEX_TAG|JSON_HEX_AMP|... so "</script>" cannot appear.
     data-seo marks tags the storefront replaces on client-side navigation. --}}
<title data-seo>{{ $p['title'] }}</title>
<meta data-seo name="description" content="{{ $p['description'] }}">
<meta data-seo name="robots" content="{{ $p['robots'] }}">
@if (! empty($p['canonical_url']))
<link data-seo rel="canonical" href="{{ $p['canonical_url'] }}">
@endif
<meta data-seo property="og:site_name" content="{{ $p['og']['site_name'] }}">
<meta data-seo property="og:type" content="{{ $p['og']['type'] }}">
<meta data-seo property="og:title" content="{{ $p['og']['title'] }}">
<meta data-seo property="og:description" content="{{ $p['og']['description'] }}">
@if (! empty($p['og']['url']))
<meta data-seo property="og:url" content="{{ $p['og']['url'] }}">
@endif
@if (! empty($p['og']['image']))
<meta data-seo property="og:image" content="{{ $p['og']['image'] }}">
@endif
<meta data-seo name="twitter:card" content="{{ empty($p['og']['image']) ? 'summary' : 'summary_large_image' }}">
<meta data-seo name="twitter:title" content="{{ $p['og']['title'] }}">
<meta data-seo name="twitter:description" content="{{ $p['og']['description'] }}">
@if (! empty($p['og']['image']))
<meta data-seo name="twitter:image" content="{{ $p['og']['image'] }}">
@endif
@foreach ($jsonld as $block)
<script data-seo type="application/ld+json">{!! $block !!}</script>
@endforeach
