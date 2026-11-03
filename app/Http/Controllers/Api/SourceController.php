<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\FetchSourceArticles;
use App\Services\HtmlNewsExtractor;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Source::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'url'])
        );
    }

    public function store(Request $request, HtmlNewsExtractor $extractor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:2048', 'unique:sources,url'],
        ]);

        $name = trim((string) ($data['name'] ?? ''));

        $data['name'] = $name !== ''
            ? $name
            : ($extractor->siteName($data['url'])
                ?? Str::of((string) parse_url($data['url'], PHP_URL_HOST))
                ->replaceMatches('/^www\./i', '')
                ->toString());

        $source = Source::create($data);

        FetchSourceArticles::dispatchAfterResponse($source);

        return response()->json($source, 201);
    }
}
