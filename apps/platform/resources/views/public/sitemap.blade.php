{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($pages as $page)<url><loc>{{ $page->slug === 'home' ? route('home') : route('public.'.$page->slug) }}</loc><lastmod>{{ $page->updated_at->toAtomString() }}</lastmod></url>@endforeach
@foreach ($articles as $article)<url><loc>{{ route('public.articles.show', $article->slug) }}</loc><lastmod>{{ $article->updated_at->toAtomString() }}</lastmod></url>@endforeach
</urlset>
