<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\HtmlNewsExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DebugSource extends Command
{
    protected $signature = 'news:debug {source : Source name or ID}';

    protected $description = 'Show what the extractor finds for one source';

    public function handle(HtmlNewsExtractor $extractor): int
    {
        $argument = (string) $this->argument('source');

        $source = is_numeric($argument)
            ? Source::find((int) $argument)
            : Source::whereRaw('lower(name) = ?', [Str::lower($argument)])->first();

        if ($source === null) {
            $this->error("No source matches '{$argument}'.");

            return self::FAILURE;
        }

        $this->table(['Setting', 'Value'], [
            ['url', $source->url],
            ['type', $source->type],
            ['feed_url', $source->feed_url ?? '(none)'],
            ['selector_rules', $source->selector_rules ? json_encode($source->selector_rules, JSON_UNESCAPED_UNICODE) : '(none)'],
        ]);

        if ($source->selector_rules && $source->feed_url) {
            $this->warn('selector_rules are set, so the feed is never used.');
        }

        $articles = $extractor->extract($source);
        $this->info(count($articles) . ' articles extracted, ' . $this->countWithImage($articles) . ' with an image.');

        $articles = $extractor->enrich($articles, 5);
        $this->info('After enrich() (5 page fetches max): ' . $this->countWithImage($articles) . ' with an image.');

        $this->table(
            ['Title', 'Image', 'Published (UTC)'],
            array_map(fn(array $a) => [
                Str::limit($a['title'], 40),
                $a['image_url'] ?? '(none)',
                $a['published_at'] ?? '(none)',
            ], array_slice($articles, 0, 8)),
        );

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $articles */
    private function countWithImage(array $articles): int
    {
        return count(array_filter($articles, fn(array $a): bool => filled($a['image_url'])));
    }
}
