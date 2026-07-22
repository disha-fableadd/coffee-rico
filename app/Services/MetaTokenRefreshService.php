<?php

namespace App\Services;

use App\Models\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaTokenRefreshService
{
    /**
     * Refresh Meta token
     */
    public function refreshMetaToken($initialToken = null)
    {
        try {
            // Load settings with access token, app ID and secret
            $settings = Settings::first();

            if (!$settings) {
                throw new \Exception("⚠️ No settings found. Please save config first.");
            }

            $appId = $settings->fb_app_id;
            $appSecret = $settings->fb_app_secret;

            if (!$appId || !$appSecret) {
                throw new \Exception("⚠️ Facebook App ID/Secret missing. Please configure first.");
            }

            $oldToken = $settings->access_token;

            if (!$oldToken && $initialToken) {
                $oldToken = $initialToken;
            }

            if (!$oldToken) {
                throw new \Exception("⚠️ No token found. Please connect Facebook account first.");
            }

            // Refresh request
            $url = "https://graph.facebook.com/v18.0/oauth/access_token";
            $response = Http::get($url, [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $oldToken,
            ]);

            if (!$response->successful()) {
                throw new \Exception("Failed to refresh token: " . $response->body());
            }

            $data = $response->json();
            $newToken = $data['access_token'];
            $expiresIn = $data['expires_in'];

            // Set expiry date
            $expiryDate = now()->addSeconds($expiresIn);

            $settings->access_token = $newToken;
            $settings->expires_in = $expiresIn;
            $settings->expiry_date = $expiryDate;
            $settings->save();

            Log::info("✅ Meta token refreshed successfully");

            return [
                'new_token' => $newToken,
                'expires_in' => $expiresIn,
                'expiry_date' => $expiryDate
            ];
        } catch (\Exception $e) {
            Log::error("❌ refreshMetaToken error:", ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
