<?php

class APNewsBridge extends BridgeAbstract
{
    const NAME = 'AP News (GraphQL)';
    const URI = 'https://apnews.com/';
    const DESCRIPTION = 'Returns articles from AP News sections via GraphQL API';
    const MAINTAINER = 'anlar';
    const PARAMETERS = [
        [
            'path' => [
                'name' => 'Section path',
                'type' => 'text',
                'required' => true,
                'exampleValue' => '/sports',
                'title' => 'Section path, e.g. /sports, /politics, /world-news',
            ],
        ]
    ];

    const CACHE_TIMEOUT = 1; // TODO: remove

    const GRAPHQL_ENDPOINT = 'https://apnews.com/graphql/delivery/ap/v1';
    const PERSISTED_QUERY_HASH = '3bc305abbf62e9e632403a74cc86dc1cba51156d2313f09b3779efec51fc3acb';

    public function getURI()
    {
        $path = $this->getInput('path');
        if ($path) {
            return self::URI . ltrim($path, '/');
        }
        return parent::getURI();
    }

    public function collectData()
    {
        $path = $this->getInput('path');
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $url = self::GRAPHQL_ENDPOINT . '?' . http_build_query([
            'operationName' => 'ContentPageQuery',
            'variables' => json_encode(['path' => $path]),
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

        $main = $data['data']['Screen']['main'] ?? [];
        $seen = [];

        foreach ($main as $container) {
            $items = $this->extractItems($container);
            foreach ($items as $promo) {
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

        usort($this->items, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
    }

    private function extractItems(array $node): array
    {
        $items = [];

        if (isset($node['items']) && is_array($node['items'])) {
            $items = array_merge($items, $node['items']);
        }

        if (isset($node['columns']) && is_array($node['columns'])) {
            foreach ($node['columns'] as $column) {
                $items = array_merge($items, $this->extractItems($column));
            }
        }

        return $items;
    }
}
