<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'source_id' => ['nullable', 'integer'],
        ]);

        return response()->json(
            Article::with('source')
                ->when($filters['source_id'] ?? null, fn($query, $id) => $query->where('source_id', $id))
                ->orderByRaw('published_at IS NULL')
                ->orderByDesc('published_at')
                ->limit(100)
                ->get()
        );
    }
}
