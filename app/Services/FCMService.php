<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class FCMService
{
    protected string $credentialsPath;

    public function __construct()
    {
        $this->credentialsPath = config('services.fcm.credentials');
    }

    /**
     * Get the OAuth2 Access Token for Firebase.
     */
    public function getAccessToken(): ?string
    {
        // Cache the token for 50 minutes (3000 seconds)
        return Cache::remember('firebase_fcm_access_token', 3000, function () {
            return $this->generateAccessToken();
        });
    }

    /**
     * Generate OAuth2 Access Token using the service account JSON.
     */
    protected function generateAccessToken(): ?string
    {
        try {
            if (!file_exists($this->credentialsPath)) {
                Log::error("Firebase credentials file not found at: {$this->credentialsPath}");
                return null;
            }

            $credentials = json_decode(file_get_contents($this->credentialsPath), true);
            if (!$credentials || !isset($credentials['private_key']) || !isset($credentials['client_email'])) {
                Log::error("Invalid Firebase credentials JSON format.");
                return null;
            }

            $privateKey = $credentials['private_key'];
            $clientEmail = $credentials['client_email'];

            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $now = time();
            $payload = json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

            $base64UrlHeader = $this->base64UrlEncode($header);
            $base64UrlPayload = $this->base64UrlEncode($payload);

            $signature = '';
            $signed = openssl_sign(
                $base64UrlHeader . "." . $base64UrlPayload,
                $signature,
                $privateKey,
                OPENSSL_ALGO_SHA256
            );

            if (!$signed) {
                Log::error("Failed to sign JWT for Firebase OAuth.");
                return null;
            }

            $base64UrlSignature = $this->base64UrlEncode($signature);
            $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->failed()) {
                Log::error("Failed to fetch Firebase OAuth2 token: " . $response->body());
                return null;
            }

            $data = $response->json();
            return $data['access_token'] ?? null;
        } catch (Exception $e) {
            Log::error("Exception occurred while generating Firebase Access Token: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Base64 Url Safe Encode helper.
     */
    protected function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Send FCM notification to a specific device token.
     */
    public function sendNotification(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        try {
            if (empty($fcmToken)) {
                Log::warning("FCM: Cannot send notification, token is empty.");
                return false;
            }

            if (!file_exists($this->credentialsPath)) {
                Log::warning("FCM: Credentials file missing. Skipping dispatch to preserve flow.");
                return false;
            }

            $credentials = json_decode(file_get_contents($this->credentialsPath), true);
            $projectId = $credentials['project_id'] ?? null;

            if (!$projectId) {
                Log::error("FCM: Project ID is missing from credentials.");
                return false;
            }

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                Log::error("FCM: Could not obtain OAuth2 access token.");
                return false;
            }

            // Ensure all data values are string types (FCM HTTP v1 requires string values in the data block)
            $formattedData = [];
            foreach ($data as $key => $value) {
                $formattedData[(string) $key] = is_array($value) ? json_encode($value) : (string) $value;
            }

            $payload = [
                'message' => [
                    'token' => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ]
                ]
            ];

            if (!empty($formattedData)) {
                $payload['message']['data'] = $formattedData;
            }

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            $response = Http::withToken($accessToken)
                ->contentType('application/json')
                ->post($url, $payload);

            if ($response->failed()) {
                Log::error("FCM: Send failed. Response: " . $response->body());
                return false;
            }

            Log::info("FCM: Notification sent successfully to token {$fcmToken}. Message ID: " . ($response->json()['name'] ?? 'unknown'));
            return true;
        } catch (Exception $e) {
            Log::error("FCM: Exception sending notification: " . $e->getMessage());
            return false;
        }
    }
}
