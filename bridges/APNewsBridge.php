<?php

class APNewsBridge extends BridgeAbstract
{
    const NAME = 'AP News (GraphQL)';
    const URI = 'https://apnews.com/';
    const DESCRIPTION = 'Returns articles from AP News sections via GraphQL API';
    const MAINTAINER = 'anlar';
    const PARAMETERS = [
        [
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
        ]
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

        if (empty($data['data']['Screen'])) {
            throw new \Exception('Unexpected API response: Screen data missing');
        }

        $screen = $data['data']['Screen'];
        $filterCategory = $path === '/' ? null : ($screen['category'] ?? null);
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

                    $stamp = $promo['publishDateStamp'] ?? null;
                    if ($stamp !== null) {
                        $item['timestamp'] = (int) ($stamp / 1000);
                    }

                    $category = $promo['category'] ?? null;
                    if ($category) {
                        $item['categories'] = [$category];
                    }

                    $this->items[] = $item;
                }
            }
        }

        usort($this->items, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
    }
}
