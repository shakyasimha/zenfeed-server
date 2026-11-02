<?php

namespace App\Services;

use App\Models\Source;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * @phpstan-type ArticleData array{title: string, url: string, snippet: ?string, published_at: ?string, image_url: ?string}
 */
class HtmlNewsExtractor
{
    private const array COMMON_FEED_PATHS = ['/feed', '/rss', '/rss.xml', '/feed.xml', '/atom.xml'];

    private const array ARTICLE_TYPES = ['newsarticle', 'article', 'blogposting', 'report', 'listitem'];

    private const array IGNORED_CONTAINERS = ['nav', 'footer', 'aside'];

    private const int MAX_LINKS = 50;

    /** A container holding more links than this is a list wrapper, not a single card. */
    private const int MAX_CARD_LINKS = 8;

    private const int MAX_SNIPPET_LENGTH = 500;

    /** Dates printed without a timezone are assumed to be in this zone, then stored as UTC. */
    private const string SOURCE_TIMEZONE = 'Asia/Kathmandu';

    /**
     * Try each strategy in order and return the first non-empty result.
     *
     * @return list<ArticleData>
     */
    public function extract(Source $source): array
    {
        $html = $this->fetch($source->url);

        if ($html === null) {
            return [];
        }

        // The source URL may itself be a feed (type "rss").
        if ($this->looksLikeFeed($html)) {
            $articles = $this->parseFeed($html);

            if ($articles !== []) {
                return $articles;
            }
        }

        $rules = $source->selector_rules ?? [];

        if (! empty($rules['container']) && ! empty($rules['title']) && ! empty($rules['link'])) {
            $articles = $this->extractWithSelectors($html, $source->url, $rules);

            if ($articles !== []) {
                return $articles;
            }
        }

        $feedUrl = $source->feed_url ?: $this->discoverFeedUrl($html, $source->url);

        if ($feedUrl !== null) {
            $body = $this->fetch($feedUrl);
            $articles = $body !== null ? $this->parseFeed($body) : [];

            if ($articles !== []) {
                if ($source->feed_url !== $feedUrl) {
                    $source->update(['feed_url' => $feedUrl]);
                }

                return $articles;
            }
        }

        $articles = $this->extractFromJsonLd($html, $source->url);

        if ($articles !== []) {
            return $articles;
        }

        return $this->extractFromLinks($html, $source->url);
    }

    /**
     * Fill in missing snippet, date, or image from each article page's meta tags.
     *
     * @param  list<ArticleData>  $articles
     * @return list<ArticleData>
     */
    public function enrich(array $articles, int $limit = 15): array
    {
        $fetched = 0;

        foreach ($articles as $index => $article) {
            if ($fetched >= $limit) {
                break;
            }

            if (filled($article['snippet']) && filled($article['published_at']) && filled($article['image_url'])) {
                continue;
            }

            $html = $this->fetch($article['url'], 15);
            $fetched++;

            if ($html === null) {
                continue;
            }

            $crawler = new Crawler($html, $article['url']);

            if (blank($articles[$index]['snippet'])) {
                $description = $this->firstAttribute($crawler, 'meta[property="og:description"], meta[name="description"]', 'content');
                $articles[$index]['snippet'] = $description !== null ? $this->cleanSnippet($description) : null;
            }

            if (blank($articles[$index]['image_url'])) {
                $articles[$index]['image_url'] = $this->resolveUrl(
                    $this->firstAttribute($crawler, 'meta[property="og:image"], meta[name="twitter:image"]', 'content'),
                    $article['url'],
                );
            }

            if (blank($articles[$index]['published_at'])) {
                // Meta tags usually carry a timezone offset, so prefer them over <time> and the URL.
                $articles[$index]['published_at'] = $this->parseDate(
                    $this->firstAttribute($crawler, 'meta[property="article:published_time"], meta[itemprop="datePublished"]', 'content')
                        ?? $this->firstAttribute($crawler, 'article time[datetime], .news-detail time[datetime], .panel-body time[datetime]', 'datetime')
                        ?? (preg_match('/(\d{4}-\d{2}-\d{2})/', $article['url'], $matches) ? $matches[1] : null)
                );
            }
        }

        return $articles;
    }

    /**
     * @param  array<string, string>  $rules
     * @return list<ArticleData>
     */
    private function extractWithSelectors(string $html, string $pageUrl, array $rules): array
    {
        $crawler = new Crawler($html, $pageUrl);
        $articles = [];

        $crawler->filter($rules['container'])->each(function (Crawler $node) use ($rules, $pageUrl, &$articles): void {
            $titleNode = $node->filter($rules['title']);
            $linkNode = $node->filter($rules['link']);

            if ($titleNode->count() === 0 || $linkNode->count() === 0) {
                return;
            }

            $dateNode = isset($rules['date']) ? $node->filter($rules['date']) : null;
            $snippetNode = isset($rules['snippet']) ? $node->filter($rules['snippet']) : null;

            $title = $this->cleanText($titleNode->text());
            $url = $this->linkUri($linkNode->getNode(0), $pageUrl);

            if ($title === '' || ! preg_match('#^https?://#i', $url)) {
                return;
            }

            $snippet = $snippetNode?->count() ? $this->cleanSnippet($snippetNode->text()) : null;

            $articles[$url] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $snippet !== '' ? $snippet : null,
                'image_url' => $this->imageFromNode($node, $pageUrl),
                'published_at' => $dateNode?->count() ? $this->parseDate($dateNode->text()) : null,
            ];
        });

        return array_values($articles);
    }

    private function discoverFeedUrl(string $html, string $pageUrl): ?string
    {
        $crawler = new Crawler($html, $pageUrl);

        foreach ($crawler->filter('link[rel="alternate"][href]') as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $type = strtolower($node->getAttribute('type'));

            if (str_contains($type, 'rss') || str_contains($type, 'atom')) {
                return (new Link($node, $pageUrl))->getUri();
            }
        }

        $parts = parse_url($pageUrl);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        foreach (self::COMMON_FEED_PATHS as $path) {
            $body = $this->fetch($origin . $path, 8);

            if ($body !== null && $this->looksLikeFeed($body)) {
                return $origin . $path;
            }
        }

        return null;
    }

    private function looksLikeFeed(string $body): bool
    {
        return (bool) preg_match('/<(rss|feed)\b/i', substr(ltrim($body), 0, 1000));
    }

    /**
     * Parses RSS 2.0 and Atom.
     *
     * @return list<ArticleData>
     */
    private function parseFeed(string $body): array
    {
        $xml = @simplexml_load_string($body);

        if ($xml === false) {
            return [];
        }

        $articles = [];

        // RSS 2.0
        foreach ($xml->channel->item ?? [] as $item) {
            $link = trim((string) $item->link);
            $description = (string) $item->description;
            $encoded = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;

            $date = (string) $item->pubDate;
            if ($date === '') {
                $date = (string) $item->children('http://purl.org/dc/elements/1.1/')->date;
            }

            $articles[] = $this->makeArticle(
                (string) $item->title,
                $link,
                $description !== '' ? $description : $encoded,
                $date,
                $this->resolveUrl($this->feedImage($item, $description . ' ' . $encoded), $link),
            );
        }

        // Atom
        foreach ($xml->entry ?? [] as $entry) {
            $href = '';

            foreach ($entry->link as $link) {
                if ((string) ($link['rel'] ?? 'alternate') === 'alternate') {
                    $href = trim((string) $link['href']);
                    break;
                }
            }

            $summary = (string) $entry->summary;
            $content = (string) $entry->content;

            $date = (string) $entry->published;
            if ($date === '') {
                $date = (string) $entry->updated;
            }

            $articles[] = $this->makeArticle(
                (string) $entry->title,
                $href,
                $summary !== '' ? $summary : $content,
                $date,
                $this->resolveUrl($this->feedImage($entry, $summary . ' ' . $content), $href),
            );
        }

        return array_values(array_filter($articles));
    }

    /**
     * Find an image for a feed item: media:content, media:thumbnail,
     * enclosure, then the first <img> inside the item's HTML.
     */
    private function feedImage(\SimpleXMLElement $item, string $html = ''): ?string
    {
        $media = $item->children('http://search.yahoo.com/mrss/');

        $sets = [$media->content, $media->thumbnail, $item->enclosure];

        // <media:group> wraps content/thumbnail in some feeds
        if (isset($media->group)) {
            $group = $media->group->children('http://search.yahoo.com/mrss/');
            $sets[] = $group->content;
            $sets[] = $group->thumbnail;
        }

        foreach ($sets as $set) {
            foreach ($set as $node) {
                $url = trim((string) ($node['url'] ?? ''));
                $type = (string) ($node['type'] ?? '');
                $medium = (string) ($node['medium'] ?? '');

                if ($url === '') {
                    continue;
                }

                $isImage = ($type === '' || str_starts_with($type, 'image'))
                    && ($medium === '' || $medium === 'image');

                if ($isImage) {
                    return $url;
                }
            }
        }

        // Atom: <link rel="enclosure" type="image/..." href="...">
        foreach ($item->link ?? [] as $link) {
            if ((string) $link['rel'] === 'enclosure' && str_starts_with((string) $link['type'], 'image')) {
                return (string) $link['href'];
            }
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            return html_entity_decode($matches[1]);
        }

        return null;
    }

    /**
     * @return list<ArticleData>
     */
    private function extractFromJsonLd(string $html, string $pageUrl): array
    {
        $crawler = new Crawler($html, $pageUrl);
        $articles = [];

        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode(trim($script->textContent), true);

            if (is_array($data)) {
                $this->walkJsonLd($data, $pageUrl, $articles);
            }
        }

        $unique = [];

        foreach (array_filter($articles) as $article) {
            $unique[$article['url']] ??= $article;
        }

        return array_values($unique);
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<ArticleData|null>  $articles
     */
    private function walkJsonLd(array $data, string $pageUrl, array &$articles): void
    {
        $types = array_map('strtolower', array_filter((array) ($data['@type'] ?? []), 'is_string'));

        if (array_intersect($types, self::ARTICLE_TYPES) !== []) {
            $source = is_array($data['item'] ?? null) ? $data['item'] : $data;

            $url = $source['url'] ?? (is_array($source['mainEntityOfPage'] ?? null) ? ($source['mainEntityOfPage']['@id'] ?? null) : ($source['mainEntityOfPage'] ?? null));

            if (is_string($url)) {
                $url = $this->resolveUrl($url, $pageUrl) ?? $url;

                $articles[] = $this->makeArticle(
                    (string) ($source['headline'] ?? $source['name'] ?? ''),
                    $url,
                    (string) ($source['description'] ?? ''),
                    (string) ($source['datePublished'] ?? ''),
                    $this->resolveUrl($this->jsonLdImage($source['image'] ?? null), $url),
                );
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->walkJsonLd($value, $pageUrl, $articles);
            }
        }
    }

    /**
     * JSON-LD images can be a string, an ImageObject, or a list of either.
     */
    private function jsonLdImage(mixed $image): ?string
    {
        if (is_string($image)) {
            return $image;
        }

        if (! is_array($image)) {
            return null;
        }

        if (isset($image['url']) && is_string($image['url'])) {
            return $image['url'];
        }

        if (array_is_list($image)) {
            foreach ($image as $item) {
                $found = $this->jsonLdImage($item);

                if ($found !== null && $found !== '') {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Last resort: pick out article-looking links from a listing page.
     *
     * @return list<ArticleData>
     */
    private function extractFromLinks(string $html, string $pageUrl): array
    {
        $crawler = new Crawler($html, $pageUrl);
        $pageHost = $this->normalizeHost($pageUrl);
        $pagePath = rtrim((string) parse_url($pageUrl, PHP_URL_PATH), '/');
        $articles = [];

        foreach ($crawler->filter('a[href]') as $anchor) {
            if (! $anchor instanceof \DOMElement) {
                continue;
            }

            $title = $this->cleanText($anchor->textContent);

            if (mb_strlen($title) < 25 || count(explode(' ', $title)) < 4) {
                continue;
            }

            if ($this->isInsideIgnoredContainer($anchor)) {
                continue;
            }

            $url = $this->linkUri($anchor, $pageUrl);

            if ($url === '' || ! preg_match('#^https?://#i', $url) || $this->normalizeHost($url) !== $pageHost) {
                continue;
            }

            $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

            if ($path === '' || $path === $pagePath || isset($articles[$url])) {
                continue;
            }

            [$snippet, $date, $imageUrl] = $this->contextFor($anchor, $pageUrl);

            $articles[$url] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $snippet,
                'image_url' => $imageUrl,
                'published_at' => $date,
            ];

            if (count($articles) >= self::MAX_LINKS) {
                break;
            }
        }

        return array_values($articles);
    }

    /**
     * True for links in a nav, footer or aside, and for links in a page-level
     * <header> (but not a header that belongs to an <article> card).
     */
    private function isInsideIgnoredContainer(\DOMElement $element): bool
    {
        $sawHeader = false;

        for ($node = $element->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            $name = strtolower($node->nodeName);

            if (in_array($name, self::IGNORED_CONTAINERS, true)) {
                return true;
            }

            if ($name === 'header') {
                $sawHeader = true;
            }

            if (in_array($name, ['article', 'main'], true)) {
                return false;
            }
        }

        return $sawHeader;
    }

    /**
     * Look at the anchor's surrounding card/row for a date, paragraph, and image.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function contextFor(\DOMElement $anchor, string $pageUrl): array
    {
        $parent = $anchor->parentNode;

        for ($depth = 0; $depth < 4 && $parent instanceof \DOMElement; $depth++, $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->nodeName), ['body', 'html', 'main'], true)) {
                break;
            }

            $context = new Crawler($parent);

            // Too many links means we've climbed out of the card into the list wrapper.
            if ($context->filter('a[href]')->count() > self::MAX_CARD_LINKS) {
                break;
            }

            $date = $this->firstAttribute($context, 'time[datetime]', 'datetime');
            $paragraph = $context->filter('p')->count() ? $this->cleanSnippet($context->filter('p')->first()->text()) : null;
            $imgUrl = $this->imageFromNode($context, $pageUrl);

            if (blank($paragraph)) {
                $paragraph = null;
            }

            if ($date !== null || $paragraph !== null || $imgUrl !== null) {
                return [$paragraph, $this->parseDate($date), $imgUrl];
            }
        }

        return [null, null, null];
    }

    /**
     * @return ArticleData|null
     */
    private function makeArticle(string $title, string $url, string $snippet, string $date, ?string $imageUrl = null): ?array
    {
        $title = $this->cleanText(strip_tags($title));
        $url = trim($url);

        if ($title === '' || $url === '') {
            return null;
        }

        $snippet = $this->cleanSnippet($snippet);

        return [
            'title' => $title,
            'url' => $url,
            'snippet' => $snippet !== '' ? $snippet : null,
            'image_url' => $imageUrl !== null && trim($imageUrl) !== '' ? trim($imageUrl) : null,
            'published_at' => $this->parseDate($date),
        ];
    }

    /**
     * Decode entities and collapse whitespace.
     */
    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Strip markup, collapse whitespace, and cap the length so a full-content
     * feed doesn't end up stored as the snippet.
     */
    private function cleanSnippet(string $value): string
    {
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return Str::limit($this->cleanText($value), self::MAX_SNIPPET_LENGTH);
    }

    /**
     * Returns a UTC "Y-m-d H:i:s" string. Dates with their own offset keep it;
     * dates without one are treated as Nepal time.
     */
    private function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Date::parse(trim($value), self::SOURCE_TIMEZONE)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function linkUri(?\DOMNode $node, string $base = 'http://localhost'): string
    {
        if (! $node instanceof \DOMElement) {
            return '';
        }

        try {
            $uri = (new Link($node, $base))->getUri();
        } catch (\Throwable) {
            return '';
        }

        return explode('#', $uri)[0];
    }

    private function normalizeHost(string $url): string
    {
        return strtolower((string) preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST)));
    }

    private function firstAttribute(Crawler $crawler, string $selector, string $attribute): ?string
    {
        $nodes = $crawler->filter($selector);

        if ($nodes->count() === 0) {
            return null;
        }

        $value = trim((string) $nodes->first()->attr($attribute));

        return $value !== '' ? $value : null;
    }

    private function fetch(string $url, int $timeout = 20): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; FinancialNewsBot/1.0)',
                'Accept-Language' => 'en,ne;q=0.8',
            ])->timeout($timeout)->get($url);
        } catch (\Throwable $e) {
            logger()->warning("Fetch failed: {$url} => {$e->getMessage()}");

            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * Make a possibly relative URL absolute. Returns null for empty or inline data: URLs.
     */
    private function resolveUrl(?string $url, string $base): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }

        try {
            return UriResolver::resolve($url, $base);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * First usable image inside a node, handling lazy-loading attributes.
     */
    private function imageFromNode(Crawler $node, string $base): ?string
    {
        foreach ($node->filter('img') as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }

            foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attr) {
                $resolved = $this->resolveUrl($img->getAttribute($attr), $base);

                if ($resolved !== null && ! $this->isPlaceholderImage($resolved)) {
                    return $resolved;
                }
            }

            foreach (['data-srcset', 'srcset'] as $attr) {
                $srcset = trim($img->getAttribute($attr));

                if ($srcset !== '') {
                    $first = explode(' ', trim(explode(',', $srcset)[0]))[0];
                    $resolved = $this->resolveUrl($first, $base);

                    if ($resolved !== null && ! $this->isPlaceholderImage($resolved)) {
                        return $resolved;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Skip the blank/spinner images that lazy-loading sites put in src.
     */
    private function isPlaceholderImage(string $url): bool
    {
        return (bool) preg_match('~(blank|placeholder|spacer|pixel|loading|loader|lazy)[^/]*\.(gif|png|jpe?g|svg)(\?.*)?$~i', $url);
    }
}
