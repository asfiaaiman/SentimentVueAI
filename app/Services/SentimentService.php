<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SentimentService
{
    public function analyze(string $text): array
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return ['label' => 'unknown', 'confidence' => 0.0];
        }

        $cacheKey = 'ml:sentiment:' . md5(Str::lower($normalized));
        $ttl = (int) config('ml.cache_ttl');
        if ($cached = cache()->get($cacheKey)) {
            return $cached;
        }

        $response = Http::timeout((int) config('ml.timeout'))
            ->post(rtrim((string) config('ml.server_url'), '/') . '/analyze', [
                'text' => $normalized,
            ]);

        if ($response->failed()) {
            $response->throw();
        }

        $data = $response->json();

        $result = [
            'label' => $data['label'] ?? 'unknown',
            'confidence' => (float) ($data['confidence'] ?? 0.0),
        ];

        cache()->put($cacheKey, $result, now()->addSeconds($ttl));
        return $result;
    }

    public function analyzeBatch(array $texts): array
    {
        $normalizedList = array_map(function ($t) {
            return is_string($t) ? trim($t) : '';
        }, $texts);

        // Preserve original order while skipping empties
        $indexToText = [];
        foreach ($normalizedList as $idx => $t) {
            if ($t !== '') {
                $indexToText[$idx] = $t;
            }
        }
        if (empty($indexToText)) {
            return [];
        }

        $ttl = (int) config('ml.cache_ttl');
        $timeout = (int) config('ml.timeout');

        // Try cache first
        $cachedResults = [];
        $misses = [];
        foreach ($indexToText as $idx => $t) {
            $cacheKey = 'ml:sentiment:' . md5(Str::lower($t));
            $cached = cache()->get($cacheKey);
            if ($cached) {
                $cachedResults[$idx] = array_merge($cached, ['text' => $t]);
            } else {
                $misses[$idx] = $t;
            }
        }

        $fetched = [];
        if (!empty($misses)) {
            $response = Http::timeout($timeout)
                ->post(rtrim((string) config('ml.server_url'), '/') . '/batch', [
                    'texts' => array_values($misses),
                ]);

            if ($response->failed()) {
                $response->throw();
            }

            $data = $response->json();
            $items = $data['items'] ?? [];
            // Map back by text value; if duplicates exist, assign sequentially
            $textToQueue = [];
            foreach ($items as $item) {
                $textToQueue[$item['text']][] = $item;
            }
            foreach ($misses as $idx => $t) {
                $queue = $textToQueue[$t] ?? [];
                $item = array_shift($queue) ?? ['label' => 'unknown', 'confidence' => 0.0, 'text' => $t];
                $textToQueue[$t] = $queue;
                $result = [
                    'label' => $item['label'] ?? 'unknown',
                    'confidence' => (float) ($item['confidence'] ?? 0.0),
                ];
                $full = array_merge($result, [
                    'text' => $t,
                    'emotion_label' => $item['emotion_label'] ?? null,
                    'emotion_confidence' => isset($item['emotion_confidence']) ? (float) $item['emotion_confidence'] : null,
                ]);
                $fetched[$idx] = $full;
                cache()->put('ml:sentiment:' . md5(Str::lower($t)), $result, now()->addSeconds($ttl));
            }
        }

        // Merge cached and fetched, sorted by original indices
        $merged = $cachedResults + $fetched;
        ksort($merged);
        return array_values($merged);
    }
}


