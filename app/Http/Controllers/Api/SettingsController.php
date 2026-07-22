<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Settings;
use App\Services\MetaTokenRefreshService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    /**
     * Get all settings
     */
    public function index(Request $request)
    {
        try {
            $settings = Settings::first();
            
            if (!$settings) {
                $settings = new Settings();
            }

            return response()->json([
                'status' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create or update a setting
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fb_app_id' => 'nullable|string',
            'fb_app_secret' => 'nullable|string',
            'phoneNumberId' => 'nullable|string',
            'waba_id' => 'nullable|string',
            'access_token' => 'nullable|string',
            'webhook_url' => 'nullable|string',
            'isActive' => 'nullable|boolean',
            'expires_in' => 'nullable|integer',
            'expiry_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $settings = Settings::first();
            
            if ($settings) {
                $settings->update($request->only([
                    'fb_app_id', 'fb_app_secret', 'phoneNumberId', 'waba_id',
                    'access_token', 'webhook_url', 'isActive', 'expires_in', 'expiry_date'
                ]));
            } else {
                $settings = Settings::create($request->only([
                    'fb_app_id', 'fb_app_secret', 'phoneNumberId', 'waba_id',
                    'access_token', 'webhook_url', 'isActive', 'expires_in', 'expiry_date'
                ]));
            }

            $webhookSubscription = $this->ensureWabaWebhookSubscription($settings->fresh());

            return response()->json([
                'status' => true,
                'message' => 'Settings updated successfully',
                'data' => $settings,
                'webhook_subscription' => $webhookSubscription,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific setting
     */
    public function show(Request $request, $id)
    {
        try {
            $setting = Settings::find($id);

            if (!$setting) {
                return response()->json([
                    'status' => false,
                    'message' => 'Setting not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $setting
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a setting
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'value' => 'nullable|string',
            'type' => 'sometimes|string|in:string,number,boolean,json',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $setting = Settings::find($id);

            if (!$setting) {
                return response()->json([
                    'status' => false,
                    'message' => 'Setting not found'
                ], 404);
            }

            $setting->update($request->only(['value', 'type']));

            return response()->json([
                'status' => true,
                'message' => 'Setting updated successfully',
                'data' => $setting
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a setting
     */
    public function destroy(Request $request, $id)
    {
        try {
            $setting = Settings::find($id);

            if (!$setting) {
                return response()->json([
                    'status' => false,
                    'message' => 'Setting not found'
                ], 404);
            }

            $setting->delete();

            return response()->json([
                'status' => true,
                'message' => 'Setting deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Configure WhatsApp Business API settings
     */
    public function configureWhatsApp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'access_token' => 'required|string',
            'phoneNumberId' => 'required|string',
            'waba_id' => 'required|string',
            'fb_app_id' => 'required|string',
            'fb_app_secret' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // Deactivate all existing settings
            Settings::query()->update(['isActive' => false]);

            // Create new active settings
            $settings = Settings::create([
                'access_token' => $request->access_token,
                'phoneNumberId' => $request->phoneNumberId,
                'waba_id' => $request->waba_id,
                'fb_app_id' => $request->fb_app_id,
                'fb_app_secret' => $request->fb_app_secret,
                'isActive' => true,
            ]);

            $webhookSubscription = $this->ensureWabaWebhookSubscription($settings);

            return response()->json([
                'status' => true,
                'message' => 'WhatsApp configuration saved successfully',
                'data' => $settings,
                'webhook_subscription' => $webhookSubscription,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refresh Meta token
     */
    public function refreshToken(Request $request)
    {
        try {
            $metaTokenService = new MetaTokenRefreshService();
            $result = $metaTokenService->refreshMetaToken();

            return response()->json([
                'status' => true,
                'message' => 'Token refreshed successfully',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get WhatsApp configuration
     */
    public function getWhatsAppConfig(Request $request)
    {
        try {
            $settings = Settings::where('isActive', true)->first();

            if (!$settings) {
                return response()->json([
                    'status' => false,
                    'message' => 'No WhatsApp configuration found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'phoneNumberId' => $settings->phoneNumberId,
                    'waba_id' => $settings->waba_id,
                    'fb_app_id' => $settings->fb_app_id,
                    'expires_in' => $settings->expires_in,
                    'expiry_date' => $settings->expiry_date,
                    'isActive' => $settings->isActive,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Connect Facebook account
     */
    public function connectFacebook(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'access_token' => 'required|string',
            'waba_id' => 'required|string',
            'phoneNumberId' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // Deactivate all existing settings
            Settings::query()->update(['isActive' => false]);

            // Create new active settings
            $settings = Settings::create([
                'access_token' => $request->access_token,
                'waba_id' => $request->waba_id,
                'phoneNumberId' => $request->phoneNumberId,
                'isActive' => true,
            ]);

            $webhookSubscription = $this->ensureWabaWebhookSubscription($settings);

            return response()->json([
                'status' => true,
                'message' => 'Facebook account connected successfully',
                'data' => $settings,
                'webhook_subscription' => $webhookSubscription,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save settings configuration
     */
    public function saveSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fb_app_id' => 'nullable|string',
            'fb_app_secret' => 'nullable|string',
            'phoneNumberId' => 'nullable|string',
            'waba_id' => 'nullable|string',
            'access_token' => 'nullable|string',
            'webhook_url' => 'nullable|string',
            'isActive' => 'nullable|boolean',
            'expires_in' => 'nullable|integer',
            'expiry_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $settings = Settings::first();
            
            if ($settings) {
                $settings->update($request->only([
                    'fb_app_id', 'fb_app_secret', 'phoneNumberId', 'waba_id',
                    'access_token', 'webhook_url', 'isActive', 'expires_in', 'expiry_date'
                ]));
            } else {
                $settings = Settings::create($request->only([
                    'fb_app_id', 'fb_app_secret', 'phoneNumberId', 'waba_id',
                    'access_token', 'webhook_url', 'isActive', 'expires_in', 'expiry_date'
                ]));
            }

            $webhookSubscription = $this->ensureWabaWebhookSubscription($settings->fresh());

            return response()->json([
                'status' => true,
                'message' => 'Settings saved successfully',
                'data' => $settings,
                'webhook_subscription' => $webhookSubscription,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ensure Meta delivers real message + status webhooks by subscribing the app to the WABA.
     */
    private function ensureWabaWebhookSubscription(Settings $settings): array
    {
        if (empty($settings->waba_id) || empty($settings->access_token)) {
            return [
                'success' => false,
                'message' => 'Skipped: waba_id or access_token missing',
            ];
        }

        try {
            return (new WhatsAppService())->subscribeAppToWaba($settings);
        } catch (\Exception $e) {
            Log::error('ensureWabaWebhookSubscription failed: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
