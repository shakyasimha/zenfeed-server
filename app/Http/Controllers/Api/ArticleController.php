<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;

class ArticleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Article::with('source')
                ->orderByRaw('published_at IS NULL')   // undated articles last
                ->orderByDesc('published_at')
                ->limit(100)
                ->get()
        );
    }
}
