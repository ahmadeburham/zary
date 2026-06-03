<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class IdempotencyMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip entirely for ML / recommender proxy routes — file uploads must not be hashed or cached
        if ($request->is('api/ml/*') || $request->is('api/recommender/*')) {
            return $next($request);
        }

        // Only run idempotency on state-changing requests (POST, PUT, PATCH, DELETE)
        if (!$request->isMethodSafe() && $request->hasHeader('X-Idempotency-Key')) {
            $idempotencyKey = $request->header('X-Idempotency-Key');
            $requestHash = md5($request->getMethod() . '|' . $request->fullUrl() . '|' . json_encode($request->all()));

            // Find existing key
            $keyRecord = IdempotencyKey::where('key', $idempotencyKey)->first();

            if ($keyRecord) {
                if ($keyRecord->status === 'processing') {
                    return response()->json([
                        'message' => 'An identical request is already processing.',
                    ], Response::HTTP_CONFLICT);
                }

                if ($keyRecord->status === 'completed') {
                    $cached = $keyRecord->response;
                    $status = $cached['status'] ?? Response::HTTP_OK;
                    $headers = $cached['headers'] ?? [];
                    $content = $cached['content'] ?? [];

                    return response()->json($content, $status, $headers);
                }

                // If failed, delete the key to allow retry
                if ($keyRecord->status === 'failed') {
                    $keyRecord->delete();
                }
            }

            // Register key as processing
            $keyRecord = IdempotencyKey::create([
                'key' => $idempotencyKey,
                'operation' => $request->method() . ':' . $request->path(),
                'request_hash' => $requestHash,
                'status' => 'processing',
            ]);

            try {
                $response = $next($request);

                // If status code is 2xx, mark completed
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $content = json_decode($response->getContent(), true) ?: $response->getContent();
                    
                    $keyRecord->update([
                        'status' => 'completed',
                        'response' => [
                            'status' => $response->getStatusCode(),
                            'headers' => [
                                'Content-Type' => $response->headers->get('Content-Type')
                            ],
                            'content' => $content,
                        ],
                        'processed_at' => now(),
                    ]);
                } else {
                    // Non-2xx is treated as failed so the client can retry
                    $keyRecord->update([
                        'status' => 'failed',
                        'error_message' => 'Response status ' . $response->getStatusCode(),
                        'processed_at' => now(),
                    ]);
                }

                return $response;
            } catch (Throwable $e) {
                $keyRecord->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'processed_at' => now(),
                ]);
                throw $e;
            }
        }

        return $next($request);
    }
}
