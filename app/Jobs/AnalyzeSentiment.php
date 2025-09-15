<?php

namespace App\Jobs;

use App\Services\SentimentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeSentiment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $requestId,
        public string $text,
        public int $ttlSeconds = 86400,
    ) {}

    public function handle(SentimentService $service): void
    {
        $cacheKey = "sentiment:".$this->requestId;
        cache()->put($cacheKey, [
            'status' => 'processing',
        ], now()->addSeconds($this->ttlSeconds));

        try {
            $result = $service->analyze($this->text);
            cache()->put($cacheKey, [
                'status' => 'done',
                'result' => $result,
            ], now()->addSeconds($this->ttlSeconds));
        } catch (\Throwable $e) {
            cache()->put($cacheKey, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ], now()->addSeconds($this->ttlSeconds));
            throw $e;
        }
    }
}


