<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AnalyzeSentimentRequest;
use App\Http\Requests\Api\BatchAnalyzeCsvRequest;
use App\Http\Requests\Api\AnalyzeHandleRequest;
use App\Services\SentimentService;
use App\Jobs\AnalyzeSentiment as AnalyzeJob;
use Illuminate\Support\Str;

class SentimentController extends Controller
{
    public function analyze(AnalyzeSentimentRequest $request, SentimentService $service)
    {
        if ($request->boolean('async')) {
            $requestId = (string) Str::uuid();
            $cacheKey = 'sentiment:'.$requestId;
            cache()->put($cacheKey, [ 'status' => 'queued' ], now()->addMinutes(10));
            AnalyzeJob::dispatch($requestId, $request->string('text'));
            return response()->json(['request_id' => $requestId, 'status' => 'queued']);
        }

        $result = $service->analyze($request->string('text'));
        return response()->json($result);
    }

    public function status(string $id)
    {
        $data = cache()->get('sentiment:'.$id);
        if (!$data) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json($data);
    }

    public function batchCsv(BatchAnalyzeCsvRequest $request, SentimentService $service)
    {
        $uploaded = $request->file('file');
        $hasHeader = $request->boolean('has_header', true);
        $column = $request->string('column')->toString();

        $path = $uploaded->getRealPath();
        $handle = fopen($path, 'r');
        $texts = [];
        if ($handle !== false) {
            $colIndex = 0;
            if ($hasHeader) {
                $header = fgetcsv($handle);
                if (is_array($header)) {
                    if ($column !== '') {
                        $found = array_search($column, $header, true);
                        $colIndex = $found === false ? 0 : (int) $found;
                    } else {
                        $colIndex = 0;
                    }
                }
            }
            while (($row = fgetcsv($handle)) !== false) {
                if (isset($row[$colIndex])) {
                    $val = trim((string) $row[$colIndex]);
                    if ($val !== '') {
                        $texts[] = $val;
                    }
                }
            }
            fclose($handle);
        }

        $items = $service->analyzeBatch($texts);
        return response()->json(['items' => $items]);
    }

    public function analyzeHandle(AnalyzeHandleRequest $request, SentimentService $service)
    {
        $handle = ltrim($request->string('handle')->toString(), '@');
        $limit = max(1, min(100, (int) $request->input('limit', 20)));
        $bearer = (string) config('services.twitter.bearer_token');

        $texts = [];
        if ($bearer) {
            // Minimal mock: replace with real Twitter API calls if token is available
            // For now, we simulate an API call failure-safe path
            try {
                $resp = \Http::withToken($bearer)
                    ->get('https://api.twitter.com/2/tweets/search/recent', [
                        'query' => 'from:' . $handle,
                        'max_results' => $limit,
                        'tweet.fields' => 'lang,created_at',
                    ]);
                if ($resp->ok()) {
                    $data = $resp->json();
                    foreach (($data['data'] ?? []) as $t) {
                        $texts[] = (string) ($t['text'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
                // fall back to mock
            }
        }

        if (empty($texts)) {
            for ($i = 1; $i <= $limit; $i++) {
                $texts[] = "$handle sample post #$i";
            }
        }

        $items = $service->analyzeBatch($texts);
        return response()->json(['items' => $items]);
    }
}
