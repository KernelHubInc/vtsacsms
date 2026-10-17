@extends('public.layout')

@section('content')
<article class="article-page"><header><div class="public-shell public-shell--narrow"><h1>{{ $article->title }}</h1><p>{{ $article->excerpt }}</p><span>{{ str($article->kind)->headline() }} · {{ $article->published_at?->format('F j, Y') }}@if($article->author_name) · {{ $article->author_name }}@endif</span></div></header><div class="public-shell public-shell--narrow article-body">{!! nl2br(e($article->body)) !!}</div></article>
@endsection
