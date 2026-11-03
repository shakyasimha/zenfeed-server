<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Source;
use App\Services\HtmlNewsExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchSourceArticles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(public Source $source) {}

    public function handle(HtmlNewsExtractor $extractor): void
    {
        $articles = $extractor->extract($this->source);

        if ($articles === []) {
            return;
        }

        // Reuse what is already stored so enrich() only opens pages that still lack data.
        $existing = Article::whereIn('url', array_column($articles, 'url'))->get()->keyBy('url');

        foreach ($articles as $index => $article) {
            $row = $existing->get($article['url']);

            if ($row === null) {
                continue;
            }

            foreach (['snippet', 'image_url', 'published_at'] as $field) {
                $stored = $row->getRawOriginal($field);

                if (blank($article[$field]) && filled($stored)) {
                    $articles[$index][$field] = $stored;
                }
            }
        }

        foreach ($extractor->enrich($articles, 15) as $article) {
            Article::updateOrCreate(
                ['url' => $article['url']],
                ['source_id' => $this->source->id, 'title' => $article['title']]
                    + array_filter([
                        'snippet' => $article['snippet'],
                        'image_url' => $article['image_url'],
                        'published_at' => $article['published_at'],
                    ], filled(...)),
            );
        }
    }
}
