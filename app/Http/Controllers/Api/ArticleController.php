<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Jobs\FetchSourceArticles;
use Illuminate\Support\Facades\Artisan;


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
                ->limit(20)
                ->get()
        );
    }

    // ArticleController
    public function refresh(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
        ]);

        $sources = Source::where('is_active', true)
            ->when(
                $filters['source_id'] ?? null,
                fn($q, $id) => $q->where('id', $id)
            )
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('articles')
                    ->whereColumn('articles.source_id', 'sources.id')
                    ->where('articles.updated_at', '>=', now()->subMinutes(2));
            })
            ->get();

        foreach ($sources as $source) {
            FetchSourceArticles::dispatch($source);
        }

        return response()->json(['queued' => $sources->count()], 202);
    }

    // using FetchFinancialNews ingestion logic
    public function fetch(Request $request): JsonResponse
    {
        // $secret = config('services.cron.secret');

        // abort_unless(
        //     is_string($secret) &&
        //         $secret !== '' &&
        //         hash_equals($secret, (string) $request->header('X-Cron-Secret')),
        //     403
        // );

        $exitCode = Artisan::call('news:fetch');

        return response()->json([
            'message' => $exitCode === 0
                ? 'News ingestion completed successfully.'
                : 'News ingestion failed.',
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ], $exitCode === 0 ? 200 : 500);
    }
}
