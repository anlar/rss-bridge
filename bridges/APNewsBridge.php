<?php

class APNewsBridge extends BridgeAbstract
{
    const NAME = 'AP News (GraphQL)';
    const URI = 'https://apnews.com/';
    const DESCRIPTION = 'Returns articles from AP News sections via GraphQL API';
    const MAINTAINER = 'anlar';
    const PARAMETERS = [
        'Standard Category' => [
            'category' => [
                'name' => 'Category',
                'type' => 'list',
                'values' => [
                    'All'              => '/',
                    'AP Fact Check'    => '/ap-fact-check',
                    'Business'         => '/business',
                    'Climate'          => '/climate-and-environment',
                    'Entertainment'    => '/entertainment',
                    'Health'           => '/health',
                    'Lifestyle'        => '/lifestyle',
                    'Oddities'         => '/oddities',
                    'Photography'      => '/photography',
                    'Politics'         => '/politics',
                    'Religion'         => '/religion',
                    'Science'          => '/science',
                    'Sports'           => '/sports',
                    'Technology'       => '/technology',
                    'U.S. News'        => '/us-news',
                    'World News'       => '/world-news',
                ],
                'defaultValue' => '/',
            ],
	    'limit' => self::LIMIT + [
                'defaultValue' => 10,
            ],
        ],
        'Custom Category' => [
            'category' => [
                'name' => 'Path',
                'type' => 'text',
                'required' => true,
                'exampleValue' => '/hub/animals',
            ],
	    'limit' => self::LIMIT + [
                'defaultValue' => 10,
            ],
        ],
    ];

    const CACHE_TIMEOUT = 1; // TODO: remove

    const GRAPHQL_ENDPOINT = 'https://apnews.com/graphql/delivery/ap/v1';
    const PERSISTED_QUERY_HASH = '3bc305abbf62e9e632403a74cc86dc1cba51156d2313f09b3779efec51fc3acb';

    public function getURI()
    {
        $path = $this->getInput('category');
        if ($path && $path !== '/') {
            return self::URI . ltrim($path, '/');
        }
        return parent::getURI();
    }

    public function collectData()
    {
        $path = $this->getInput('category') ?: '/';

        $url = self::GRAPHQL_ENDPOINT . '?' . http_build_query([
            'operationName' => 'ContentPageQuery',
            'variables' => json_encode(['path' => $path], JSON_UNESCAPED_SLASHES),
            'extensions' => json_encode([
                'persistedQuery' => [
                    'version' => 1,
                    'sha256Hash' => self::PERSISTED_QUERY_HASH,
                ]
            ]),
        ]);

        $json = getContents($url);
        $data = json_decode($json, true);

        if (array_key_exists('Screen', $data['data'] ?? []) && $data['data']['Screen'] === null) {
            throw new \Exception('Category not found: ' . $path);
        }

        if (empty($data['data']['Screen'])) {
            throw new \Exception('Unexpected API response: Screen data missing');
        }

        $screen = $data['data']['Screen'];
        $isCustom = $this->queriedContext === 'Custom Category';
        $screenCategory = $screen['category'] ?? null;
        $filterCategory = ($isCustom || $path === '/' || $path === '/photography') ? null : $screenCategory;
        $main = $screen['main'] ?? [];
        $seen = [];

        foreach ($main as $container) {
            if (($container['__typename'] ?? null) !== 'ColumnContainer') {
                continue;
            }
            foreach ($container['columns'] ?? [] as $column) {
                if (($column['__typename'] ?? null) !== 'PageListModule') {
                    continue;
                }
                foreach ($column['items'] ?? [] as $promo) {
                    if (($promo['__typename'] ?? null) !== 'PagePromo') {
                        continue;
                    }
                    if ($filterCategory && ($promo['category'] ?? null) !== $filterCategory) {
                        continue;
                    }

                    $id = $promo['id'] ?? null;
                    $url = $promo['url'] ?? null;

                    if (!$url || !$id || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;

                    $item = [];
                    $item['uid'] = $id;
                    $item['title'] = $promo['title'] ?? '';
                    $item['content'] = $promo['description'] ?? '';
                    $item['uri'] = $url;
                    $item['_imageUrl'] = $this->extractImageUrl($promo['media'] ?? []);

                    $stamp = $promo['publishDateStamp'] ?? null;
                    if ($stamp !== null) {
                        $item['timestamp'] = (int) ($stamp / 1000);
                    }

                    $categories = array_values(array_unique(array_filter([
                        $promo['category'] ?? null,
                        $isCustom ? $screenCategory : null,
                    ])));
                    if ($categories) {
                        $item['categories'] = $categories;
                    }

                    $this->items[] = $item;
                }
            }
        }

        usort($this->items, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        $limit = (int) $this->getInput('limit');
        if ($limit > 0) {
            $this->items = array_slice($this->items, 0, $limit);
        }

        foreach ($this->items as &$item) {
            $this->collectPageData($item);
        }
    }

    private function collectPageData(array &$item): void
    {
        $imageUrl = $item['_imageUrl'];
        unset($item['_imageUrl']);

        $html = getSimpleHTMLDOM($item['uri']);
        $body = $html->find('div.RichTextStoryBody.RichTextBody', 0);
        if ($body) {
            foreach ($body->find('div.FreeStar, div.Advertisement') as $div) {
                $div->outertext = '';
            }
            $item['content'] = $body->innertext;
        }

        $isVideo = str_contains(parse_url($item['uri'], PHP_URL_PATH), '/video/');
        if ($isVideo) {
            $ldScript = $html->find('script[type="application/ld+json"]', 0);
            $videoUrl = null;
            if ($ldScript) {
                $ld = json_decode($ldScript->innertext, true);
                $videoUrl = $ld['mainEntity']['contentUrl'] ?? null;
            }
            if ($videoUrl) {
                $descMeta = $html->find('meta[property="og:description"]', 0);
                $desc = $descMeta ? '<p>' . htmlspecialchars($descMeta->content, ENT_QUOTES) . '</p>' : '';
                $item['content'] = '<video controls src="' . $videoUrl . '"></video>' . $desc;
            }
        } elseif ($imageUrl) {
            $altMeta = $html->find('meta[property="og:image:alt"]', 0);
            $alt = $altMeta ? htmlspecialchars($altMeta->content, ENT_QUOTES) : '';
            $item['content'] = '<img src="' . $imageUrl . '" alt="' . $alt . '">' . $item['content'];
        }

        $authorsDiv = $html->find('div.Page-authors', 0);
        if ($authorsDiv) {
            $nodes = $authorsDiv->find('a, span.Link');
            $names = array_map(fn($n) => $n->plaintext, $nodes);
            if ($names) {
                $item['author'] = implode(', ', $names);
            }
        }
    }

    private function extractImageUrl(array $media): ?string
    {
        foreach ($media as $m) {
            if (($m['__typename'] ?? null) !== 'Image') {
                continue;
            }
            foreach ($m['image']['entries'] ?? [] as $entry) {
                if ($entry['key'] === 'src') {
                    return $entry['value'];
                }
            }
        }
        return null;
    }

}
