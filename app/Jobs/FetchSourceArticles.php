<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Source;
use App\Services\HtmlNewsExtractor;
use Illuminate\Foundation\Bus\Dispatchable;


class FetchSourceArticles
{
    use Dispatchable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Source $source)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(HtmlNewsExtractor $extractor): void
    {
        //
        $articles = $extractor->enrich($extractor->extract($this->source), 10);

        foreach ($articles as $data) {
            Article::updateOrCreate(
                ['url' => $data['url']],
                [
                    'source_id' => $this->source->id,
                    'title' => $data['title'],
                    'snippet' => $data['snippet'],
                    'image_url' => $data['image_url'],
                    'published_at' => $data['published_at']
                ]
            );
        }
    }
}
