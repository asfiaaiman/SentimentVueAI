<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ImportReviewsCsvRequest;
use App\Jobs\AnalyzeReview;
use App\Models\Review;

class ReviewController extends Controller
{
    public function importCsv(ImportReviewsCsvRequest $request)
    {
        $file = $request->file('file');
        $hasHeader = $request->boolean('has_header', true);
        $productCol = $request->string('product_column')->toString() ?: 'product';
        $ratingCol = $request->string('rating_column')->toString() ?: 'rating';
        $textCol = $request->string('text_column')->toString() ?: 'text';
        $shouldQueue = $request->boolean('queue', true);

        $path = $file->getRealPath();
        $handle = fopen($path, 'r');
        $created = 0;
        $queued = 0;
        $errors = 0;
        $header = null;
        if ($handle !== false) {
            if ($hasHeader) {
                $header = fgetcsv($handle) ?: [];
            }
            while (($row = fgetcsv($handle)) !== false) {
                try {
                    if ($hasHeader && $header) {
                        $assoc = [];
                        foreach ($header as $i => $name) {
                            $assoc[$name] = $row[$i] ?? null;
                        }
                        $product = (string) ($assoc[$productCol] ?? '');
                        $rating = $assoc[$ratingCol] !== null ? (int) $assoc[$ratingCol] : null;
                        $text = (string) ($assoc[$textCol] ?? '');
                    } else {
                        $product = (string) ($row[0] ?? '');
                        $rating = isset($row[1]) ? (int) $row[1] : null;
                        $text = (string) ($row[2] ?? '');
                    }

                    if ($product === '' || $text === '') {
                        $errors++;
                        continue;
                    }

                    $review = Review::create([
                        'product' => trim($product),
                        'rating' => $rating,
                        'text' => trim($text),
                    ]);
                    $created++;

                    if ($shouldQueue) {
                        AnalyzeReview::dispatch($review->id);
                        $queued++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                }
            }
            fclose($handle);
        }

        return response()->json([
            'created' => $created,
            'queued' => $queued,
            'errors' => $errors,
        ]);
    }

    public function aggregates()
    {
        $byProduct = Review::selectRaw('product, COUNT(*) as total, SUM(sentiment_label = \"positive\") as pos, SUM(sentiment_label = \"negative\") as neg, SUM(sentiment_label = \"neutral\") as neu')
            ->groupBy('product')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        $byRating = Review::selectRaw('rating, COUNT(*) as total, SUM(sentiment_label = \"positive\") as pos, SUM(sentiment_label = \"negative\") as neg, SUM(sentiment_label = \"neutral\") as neu')
            ->groupBy('rating')
            ->orderBy('rating')
            ->get();

        return response()->json([
            'by_product' => $byProduct,
            'by_rating' => $byRating,
        ]);
    }
}


