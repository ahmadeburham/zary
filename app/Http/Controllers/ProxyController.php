<?php

namespace App\Http\Controllers;

use App\Services\RecommenderDatabaseSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ProxyController extends Controller
{
    private const ML_BASE          = 'http://127.0.0.1:8001';
    private const RECOMMENDER_BASE = 'http://127.0.0.1:8002';

    public function __construct(
        protected RecommenderDatabaseSync $recommenderSync,
    ) {}

    public function forwardToMl(Request $request, string $path)
    {
        return $this->forward($request, self::ML_BASE, $path);
    }

    public function forwardToRecommender(Request $request, string $path)
    {
        if (strtolower($request->method()) === 'POST' && str_contains($path, 'recommend')) {
            $this->recommenderSync->sync();
        }

        return $this->forward($request, self::RECOMMENDER_BASE, $path);
    }

    private function forward(Request $request, string $base, string $path)
    {
        $url    = rtrim($base, '/') . '/' . ltrim($path, '/');
        $method = strtoupper($request->method());

        // Allow slow ML inference — PHP's default max_execution_time would kill
        // the request before Guzzle's timeout fires, swallowing the catch block.
        set_time_limit(120);

        try {
            $pending = Http::timeout(55)->withoutVerifying();

            $sendOptions = ['query' => $request->query->all()];

            if ($request->files->count() > 0) {
                foreach ($request->files->all() as $field => $file) {
                    $files = is_array($file) ? $file : [$file];
                    foreach ($files as $i => $f) {
                        $fieldName = is_array($file) ? "{$field}[{$i}]" : $field;
                        $content   = $f->getContent();
                        $filename  = $f->getClientOriginalName() ?: ($fieldName . '.bin');
                        // Do NOT pass a headers array — Guzzle must emit Content-Disposition
                        // before Content-Type. If Content-Type precedes Content-Disposition,
                        // Werkzeug (Flask) loses the filename and the file field becomes empty.
                        $pending   = $pending->attach($fieldName, $content, $filename);
                    }
                }
                foreach ($request->request->all() as $key => $value) {
                    $pending = $pending->attach($key, (string) $value);
                }
                // 'multipart' key must be present in send() options so that
                // PendingRequest::parseHttpOptions() takes the if-branch and
                // merges $pendingFiles in. Without it the else-branch runs and
                // sets multipart=pendingBody=null, losing all attached files.
                $sendOptions['multipart'] = [];
            } else {
                $pending = $pending->withBody($request->getContent(), $request->header('Content-Type', 'application/json'));
            }

            $response = $pending->send($method, $url, $sendOptions);

            return response($response->body(), $response->status())
                ->header('Content-Type', $response->header('Content-Type') ?? 'application/json');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json([
                'error' => 'ML service is unavailable. Please try again shortly.',
                'detail' => $e->getMessage(),
            ], 503);
        } catch (\Exception $e) {
            $isTimeout = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'timeout');
            return response()->json([
                'error' => $isTimeout
                    ? 'ML service took too long to respond. Please retry.'
                    : 'Proxy error: ' . $e->getMessage(),
                'service' => $base,
            ], $isTimeout ? 504 : 502);
        }
    }
}
