<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'unique:sources,url'],
            'type' => ['sometimes', 'in:rss,html'],
            'feed_url' => ['nullable', 'url'],
        ]);

        return response()->json(Source::create($data), 201);
    }
}
