<?php

namespace App\Jobs;

use App\Models\Review;
use App\Services\SentimentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeReview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $reviewId) {}

    public function handle(SentimentService $service): void
    {
        $review = Review::find($this->reviewId);
        if (!$review) {
            return;
        }

        $result = $service->analyze($review->text);

        $review->forceFill([
            'sentiment_label' => $result['label'] ?? null,
            'sentiment_confidence' => $result['confidence'] ?? null,
            'emotion_label' => $result['emotion_label'] ?? null,
            'emotion_confidence' => $result['emotion_confidence'] ?? null,
            'analyzed_at' => now(),
        ])->save();
    }
}


