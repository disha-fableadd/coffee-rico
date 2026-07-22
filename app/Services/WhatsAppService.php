<?php

namespace App\Services;

use App\Models\Settings;
use App\Models\Template;
use App\Support\UploadPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    /**
     * Send a simple WhatsApp message
     */
    public function sendWhatsAppMessage($phone, $name = null)
    {
        try {
            $settings = Settings::where('isActive', true)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$settings) {
                Log::error('❌ No WhatsApp settings found in DB.');
                return false;
            }

            $phoneNumberId = $settings->phoneNumberId;
            $accessToken = $settings->access_token;

            // Debug logging
            Log::info('WhatsApp Settings Debug:', [
                'phoneNumberId' => $phoneNumberId,
                'accessToken' => $accessToken ? 'SET' : 'NOT SET',
                'waba_id' => $settings->waba_id,
                'isActive' => $settings->isActive
            ]);
            $templateName = "opt_in_welcome_template";

            $response = Http::timeout(30)->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json'
            ])->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'template',
                'whatsapp_marketing_optin' => true,
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => 'en'],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $name ?: 'there'],
                                ['type' => 'text', 'text' => 'Fablead']
                            ]
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                Log::info("✅ WhatsApp message sent to {$phone}");
                return true;
            } else {
                $error = $response->json();
                Log::error("❌ WhatsApp send error:", array_merge($error, ['phone' => $phone]));
                return false;
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("❌ WhatsApp send timeout/connection error:", ['error' => $e->getMessage(), 'phone' => $phone, 'type' => 'timeout']);
            return false;
        } catch (\Exception $e) {
            Log::error("❌ WhatsApp send error:", ['error' => $e->getMessage(), 'phone' => $phone]);
            return false;
        }
    }

    /**
     * Send WhatsApp message with template components
     */
    public function sendWhatsAppMessageSelected($phone, $templateName, $languageCode, $templateComponents, $variables = [], $headerVariables = [], $contact = null)
    {
        try {
            $settings = Settings::where('isActive', true)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$settings) {
                Log::error('❌ No WhatsApp settings found in DB.');
                throw new \Exception('No WhatsApp settings found');
            }

            $phoneNumberId = $settings->phoneNumberId;
            $accessToken = $settings->access_token;

            // Build dynamic components
            $processedComponents = $this->buildTemplateComponents($templateComponents, $variables, $headerVariables, $contact);
            Log::info('processedComponents', ['processedComponents' => $processedComponents]);
            $response = Http::timeout(30)->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json'
            ])->post("https://graph.facebook.com/v22.0/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'template',
                'whatsapp_marketing_optin' => true,
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => $languageCode],
                    'components' => $processedComponents
                ]
            ]);

            if ($response->successful()) {
                Log::info("✅ WhatsApp message sent to {$phone} using {$templateName}");
                Log::info($response->json() );
                return [
                    'success' => true,
                    'message_id' => $response->json()['messages'][0]['id'] ?? null
                ];
            } else {
                $error = $response->json();
                Log::error("❌ WhatsApp send error:", array_merge($error, ['phone' => $phone]));
                throw new \Exception($error['error']['message'] ?? 'WhatsApp API error');
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("❌ WhatsApp send timeout/connection error:", ['error' => $e->getMessage(), 'phone' => $phone, 'type' => 'timeout']);
            throw new \Exception('WhatsApp API timeout: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error("❌ WhatsApp send error:", ['error' => $e->getMessage(), 'phone' => $phone]);
            throw $e;
        }
    }

    /**
     * Send custom template message
     */
    public function sendCustomTemplate($phone, $templateName, $variables = [], $contact = null)
    {
        try {
            $settings = Settings::where('isActive', true)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$settings) {
                Log::error('❌ No WhatsApp settings found in DB.');
                return [
                    'success' => false,
                    'error' => 'No WhatsApp settings found'
                ];
            }

            // Fetch template from DB
            Log::info("🔍 Looking for template: '{$templateName}'");

            $customTemplate = Template::where('name', $templateName)
                ->whereJsonContains('components', ['type' => 'BODY'])
                ->first();

            if (!$customTemplate) {
                $availableTemplates = Template::pluck('name')->toArray();
                return [
                    'success' => false,
                    'error' => "Template '{$templateName}' not found in database",
                    'available_templates' => $availableTemplates
                ];
            }

            $phoneNumberId = $settings->phoneNumberId;
            $accessToken = $settings->access_token;

            // Validate & Replace Variables
            $messageContent = "";

            if ($customTemplate->components && is_array($customTemplate->components)) {
                $bodyComponent = collect($customTemplate->components)->firstWhere('type', 'BODY');

                if ($bodyComponent && isset($bodyComponent['text'])) {
                    $messageContent = trim($bodyComponent['text']);
                }
            }

            if (empty($messageContent)) {
                return [
                    'success' => false,
                    'error' => "Template '{$templateName}' has no BODY content"
                ];
            }

            // Replace variables
            if (is_string($variables)) {
                Log::info('dynamicvalue', ['value' => $variables]);
                $value = $variables ?: ($contact ? $this->getContactVariable($contact, 0) : "");
                $dynamicValue = $this->resolveVariable($value, $contact);
                $messageContent = preg_replace('/\{\{\s*1\s*\}\}/', $dynamicValue, $messageContent);
            } elseif (is_array($variables) && count($variables) > 0) {
                foreach ($variables as $index => $variable) {
                    $placeholder = '/\{\{\s*' . ($index + 1) . '\s*\}\}/';
                    $value = $variable ?: ($contact ? $this->getContactVariable($contact, $index) : "");
                    $dynamicValue = $this->resolveVariable($value, $contact);
                    $messageContent = preg_replace($placeholder, $dynamicValue, $messageContent);
                }
            }

            Log::info("✅ Final Processed Message: {$messageContent}");

            // WhatsApp API Call
            $response = Http::timeout(30)->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json'
            ])->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'text',
                'text' => ['body' => $messageContent]
            ]);

            if ($response->successful()) {
                Log::info("✅ Custom template message sent to {$phone}");
                return [
                    'success' => true,
                    'phone' => $phone,
                    'template_name' => $templateName,
                    'message_content' => $messageContent,
                    'message_id' => $response->json()['messages'][0]['id'] ?? null
                ];
            } else {
                $error = $response->json();
                Log::error("❌ Custom template send error:", array_merge($error, ['phone' => $phone, 'template_name' => $templateName]));
                return [
                    'success' => false,
                    'error' => $error['error']['message'] ?? 'WhatsApp API error',
                    'phone' => $phone,
                    'template_name' => $templateName
                ];
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("❌ Custom template send timeout/connection error:", ['error' => $e->getMessage(), 'phone' => $phone, 'template_name' => $templateName, 'type' => 'timeout']);
            return [
                'success' => false,
                'error' => 'WhatsApp API timeout: ' . $e->getMessage(),
                'phone' => $phone,
                'template_name' => $templateName
            ];
        } catch (\Exception $e) {
            Log::error("❌ Custom template send error:", ['error' => $e->getMessage(), 'phone' => $phone, 'template_name' => $templateName]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'phone' => $phone,
                'template_name' => $templateName
            ];
        }
    }

    /**
     * Build template components
     */
    private function buildTemplateComponents($templateComponents, $variables = [], $headerVariables = [], $contact = null)
    {
        $components = [];

        foreach ($templateComponents as $component) {
            $processedComponent = ['type' => strtolower($component['type'])];

            switch (strtoupper($component['type'])) {
                case 'HEADER':
                    if (!isset($component['format'])) break;

                    $format = strtoupper($component['format']);
                    switch ($format) {
                        case 'IMAGE':
                            // Normalize to a single URL string
                            $imageUrl = null;
                            if (is_array($headerVariables)) {
                                $first = reset($headerVariables);
                                $imageUrl = is_array($first) ? reset($first) : $first;
                            } else {
                                $imageUrl = $headerVariables;
                            }

                            if (is_string($imageUrl)) {
                                $imageUrl = trim($imageUrl);
                            }

                            Log::info('processedComponent', ['imageUrl' => $imageUrl, 'rawHeaderVariables' => $headerVariables]);

                            if (empty($imageUrl) || !is_string($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                                Log::error('❌ HEADER IMAGE URL invalid or missing', ['provided' => $headerVariables]);
                                break;
                            }

                            $processedComponent['parameters'] = [
                                [
                                    'type' => 'image',
                                    'image' => ['link' => $imageUrl]
                                ]
                            ];
                            break;

                        case 'DOCUMENT':
                            if (isset($component['text']) || isset($component['example']['document_url'])) {
                                $processedComponent['parameters'] = [
                                    [
                                        'type' => 'document',
                                        'link' => $this->replaceVariables($component['text'] ?? $component['example']['document_url'], $variables, $contact),
                                        'filename' => $component['filename'] ?? 'document.pdf'
                                    ]
                                ];
                            }
                            break;

                        case 'VIDEO':
                            // Normalize to a single URL string (same as image handling)
                            $videoUrl = null;
                            if (is_array($headerVariables)) {
                                $first = reset($headerVariables);
                                $videoUrl = is_array($first) ? reset($first) : $first;
                            } else {
                                $videoUrl = $headerVariables;
                            }

                            if (is_string($videoUrl)) {
                                $videoUrl = trim($videoUrl);
                            }

                            Log::info('processedComponent', ['videoUrl' => $videoUrl, 'rawHeaderVariables' => $headerVariables]);

                            if (empty($videoUrl) || !is_string($videoUrl) || !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                                Log::error('❌ HEADER VIDEO URL invalid or missing', ['provided' => $headerVariables]);
                                break;
                            }

                            $processedComponent['parameters'] = [
                                [
                                    'type' => 'video',
                                    'video' => ['link' => $videoUrl]
                                ]
                            ];
                            break;

                        default:
                            Log::info("Unknown HEADER format: {$format}");
                    }
                    break;

                case 'BODY':
                    if (isset($component['text']) && isset($component['example']['body_text'])) {
                        $paramCount = count($component['example']['body_text'][0] ?? []);
                        $processedComponent['parameters'] = [];
                        for ($i = 0; $i < $paramCount; $i++) {
                            $value = $variables[$i] ?? ($contact ? $this->getContactVariable($contact, $i) : "");
                            $dynamicValue = $this->resolveVariable($value, $contact);

                            $processedComponent['parameters'][] = [
                                'type' => 'text',
                                'text' => $dynamicValue
                            ];
                        }
                    }
                    break;

                case 'FOOTER':
                    if (isset($component['text'])) {
                        $processedComponent['parameters'] = [
                            ['type' => 'text', 'text' => $this->replaceVariables($component['text'], $variables, $contact)]
                        ];
                    }
                    break;

                case 'BUTTONS':
                    if (isset($component['buttons']) && is_array($component['buttons'])) {
                        foreach ($component['buttons'] as $buttonIndex => $button) {
                            $buttonComponent = ['type' => 'button', 'index' => (string)$buttonIndex];

                            if ($button['type'] === 'URL') {
                                $buttonComponent['sub_type'] = 'url';
                                if (isset($button['url']) && strpos($button['url'], '{{1}}') !== false) {
                                    $urlParam = $variables["button_{$buttonIndex}"] ?? $variables[count($variables) - 1] ?? "";
                                    // $buttonComponent['parameters'] = [['type' => 'text', 'text' => $urlParam]];
                                    if (strpos($urlParam, 'redirectwhatsapp') !== false) {
                                        Log::info("Unknown component type: INNNN");
                                        $buttonComponent['parameters'] = [['type' => 'text', 'text' => 'redirectwhatsapp']];
                                    }else{
                                        Log::info("Unknown component type: OUTTTT");
                                        $buttonComponent['parameters'] = [['type' => 'text', 'text' => $urlParam]];
                                    }
                                } else {
                                    $buttonComponent['parameters'] = [['type' => 'text', 'text' => $button['url']]];
                                }
                            } elseif ($button['type'] === 'QUICK_REPLY') {
                                $buttonComponent['sub_type'] = 'quick_reply';
                                $buttonComponent['parameters'] = [['type' => 'text', 'text' => $button['text']]];
                            }

                            $components[] = $buttonComponent;
                        }
                        return $components;
                    }
                    break;

                default:
                    // Log::info("Unknown component type: {$component['type']}");
            }

            if (strtoupper($component['type']) !== 'BUTTONS') {
                $components[] = $processedComponent;
            }
        }

        return $components;
    }

    /**
     * Resolve variable values
     */
    private function resolveVariable($value, $contact)
    {
        if (!is_string($value)) return $value;

        if (str_starts_with($value, 'contact::')) {
            $key = explode('::', $value)[1];
            if ($contact && isset($contact[$key])) {
                return $contact[$key];
            } else {
                Log::warning("⚠️ Contact key '{$key}' not found in contact object");
                return "";
            }
        }
        return $value;
    }

    /**
     * Replace variables in text
     */
    private function replaceVariables($text, $variables, $contact)
    {
        $processedText = $text;

        if (is_array($variables)) {
            foreach ($variables as $index => $variable) {
                $placeholder = '/\{\{\s*' . ($index + 1) . '\s*\}\}/';
                $processedText = preg_replace($placeholder, $variable, $processedText);
            }
        }

        if ($contact) {
            $processedText = str_replace('{{name}}', $contact['name'] ?? $contact['firstName'] ?? '', $processedText);
            $processedText = str_replace('{{phone}}', $contact['phone'] ?? '', $processedText);
            $processedText = str_replace('{{email}}', $contact['email'] ?? '', $processedText);
        }

        return $processedText;
    }

    /**
     * Get contact variable by index
     */
    private function getContactVariable($contact, $index)
    {
        $contactFields = [
            $contact['name'] ?? '',
            $contact['firstName'] ?? '',
            $contact['phone'] ?? '',
            $contact['email'] ?? '',
            $contact['company'] ?? ''
        ];
        return $contactFields[$index] ?? "";
    }

    /**
     * Send free-form message (not template-based)
     */
    public function sendFreeFormMessage($phone, $message, $type = 'text', $mediaUrl = null, $mediaType = null, $mediaFilename = null)
    {
        try {
            $settings = Settings::where('isActive', true)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$settings) {
                Log::error('❌ No WhatsApp settings found in DB.');
                return [
                    'success' => false,
                    'error' => 'No WhatsApp settings found'
                ];
            }

            $phoneNumberId = $settings->phoneNumberId;
            $accessToken = $settings->access_token;

            // Prepare message payload based on type
            $messagePayload = $this->buildFreeFormMessagePayload($type, $message, $mediaUrl, $mediaType, $mediaFilename);

            if (!$messagePayload) {
                return [
                    'success' => false,
                    'error' => 'Invalid message type or missing required parameters'
                ];
            }

            $response = Http::timeout(30)->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json'
            ])->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => $type,
                $type => $messagePayload
            ]);

            if ($response->successful()) {
                Log::info("✅ Free-form message sent to {$phone}");
                return [
                    'success' => true,
                    'message_id' => $response->json()['messages'][0]['id'] ?? null,
                    'phone' => $phone,
                    'type' => $type
                ];
            } else {
                $error = $response->json();
                Log::error("❌ Free-form message send error:", array_merge($error, ['phone' => $phone]));
                return [
                    'success' => false,
                    'error' => $error['error']['message'] ?? 'WhatsApp API error'
                ];
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("❌ Free-form message send timeout/connection error:", ['error' => $e->getMessage(), 'phone' => $phone, 'type' => 'timeout']);
            return [
                'success' => false,
                'error' => 'WhatsApp API timeout: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            Log::error("❌ Free-form message send error:", ['error' => $e->getMessage(), 'phone' => $phone]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Download WhatsApp media using media ID
     */
    public function downloadWhatsAppMedia($mediaId)
    {
        try {
            $settings = Settings::where('isActive', true)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$settings) {
                Log::error('❌ No WhatsApp settings found in DB.');
                return [
                    'success' => false,
                    'error' => 'No WhatsApp settings found'
                ];
            }

            $accessToken = $settings->access_token;

            // First, get the media URL from WhatsApp API
            $mediaResponse = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
            ])->get("https://graph.facebook.com/v21.0/{$mediaId}");

            if (!$mediaResponse->successful()) {
                Log::error("❌ Failed to get media info from WhatsApp API:", $mediaResponse->json());
                return [
                    'success' => false,
                    'error' => 'Failed to get media info from WhatsApp API'
                ];
            }

            $mediaData = $mediaResponse->json();
            $mediaUrl = $mediaData['url'] ?? null;

            if (!$mediaUrl) {
                return [
                    'success' => false,
                    'error' => 'Media URL not found in WhatsApp response'
                ];
            }

            // Download the media file
            $mediaContent = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
            ])->get($mediaUrl);

            if (!$mediaContent->successful()) {
                Log::error("❌ Failed to download media from WhatsApp:", $mediaContent->body());
                return [
                    'success' => false,
                    'error' => 'Failed to download media from WhatsApp'
                ];
            }

            // Generate filename and save to storage
            $mimeType = $mediaData['mime_type'] ?? 'application/octet-stream';
            $extension = $this->getExtensionFromMimeType($mimeType);
            $filename = time() . '_' . $mediaId . '.' . $extension;

            // Create media directory if it doesn't exist
            $mediaPath = public_path('uploads/media');
            if (!file_exists($mediaPath)) {
                mkdir($mediaPath, 0755, true);
            }

            // Save the file
            $filePath = $mediaPath . '/' . $filename;
            file_put_contents($filePath, $mediaContent->body());

            $storedPath = UploadPath::store('uploads/media/' . $filename);

            return [
                'success' => true,
                'filename' => $filename,
                'path' => $storedPath,
                'url' => $storedPath,
                'mime_type' => $mimeType,
                'size' => strlen($mediaContent->body()),
                'media_id' => $mediaId
            ];

        } catch (\Exception $e) {
            Log::error("❌ WhatsApp media download error:", ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMimeType($mimeType)
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/avi' => 'avi',
            'video/mov' => 'mov',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt'
        ];

        return $mimeToExt[$mimeType] ?? 'bin';
    }

    /**
     * Build message payload for different message types
     */
    private function buildFreeFormMessagePayload($type, $message, $mediaUrl = null, $mediaType = null, $mediaFilename = null)
    {
        switch ($type) {
            case 'text':
                return ['body' => $message];

            case 'image':
                if (!$mediaUrl) return null;
                return [
                    'link' => $mediaUrl,
                    'caption' => $message
                ];

            case 'video':
                if (!$mediaUrl) return null;
                return [
                    'link' => $mediaUrl,
                    'caption' => $message
                ];

            case 'audio':
                if (!$mediaUrl) return null;
                return [
                    'link' => $mediaUrl
                ];

            case 'document':
                if (!$mediaUrl) return null;
                return [
                    'link' => $mediaUrl,
                    'filename' => $mediaFilename ?: 'document',
                    'caption' => $message
                ];

            case 'location':
                // For location, message should contain lat,lng format
                $coords = explode(',', $message);
                if (count($coords) !== 2) return null;
                return [
                    'latitude' => floatval(trim($coords[0])),
                    'longitude' => floatval(trim($coords[1])),
                    'name' => $mediaFilename ?: 'Location',
                    'address' => $message
                ];

            case 'contact':
                // For contact, message should contain phone number
                return [
                    'contacts' => [
                        [
                            'name' => [
                                'formatted_name' => $mediaFilename ?: 'Contact'
                            ],
                            'phones' => [
                                [
                                    'phone' => $message,
                                    'type' => 'MOBILE'
                                ]
                            ]
                        ]
                    ]
                ];

            default:
                return null;
        }
    }

    /**
     * Subscribe this Meta app to the WABA so real message/status webhooks are delivered.
     * App Dashboard webhook config alone is not enough — Meta requires POST /{waba-id}/subscribed_apps.
     */
    public function subscribeAppToWaba(?Settings $settings = null): array
    {
        $settings = $settings ?: Settings::where('isActive', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$settings || empty($settings->waba_id) || empty($settings->access_token)) {
            return [
                'success' => false,
                'message' => 'Missing active WhatsApp settings (waba_id / access_token)',
            ];
        }

        try {
            $response = Http::timeout(30)
                ->withToken($settings->access_token)
                ->post("https://graph.facebook.com/v21.0/{$settings->waba_id}/subscribed_apps");

            $body = $response->json() ?? [];

            if ($response->successful() && ($body['success'] ?? false) === true) {
                Log::info('WABA app subscription successful', [
                    'waba_id' => $settings->waba_id,
                    'fb_app_id' => $settings->fb_app_id,
                ]);

                return [
                    'success' => true,
                    'message' => 'App subscribed to WABA for webhooks',
                    'data' => $body,
                ];
            }

            Log::error('WABA app subscription failed', [
                'waba_id' => $settings->waba_id,
                'status' => $response->status(),
                'body' => $body,
            ]);

            return [
                'success' => false,
                'message' => 'Failed to subscribe app to WABA',
                'data' => $body,
            ];
        } catch (\Exception $e) {
            Log::error('WABA app subscription exception: ' . $e->getMessage(), [
                'waba_id' => $settings->waba_id,
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * List apps currently subscribed to the WABA.
     */
    public function getWabaSubscribedApps(?Settings $settings = null): array
    {
        $settings = $settings ?: Settings::where('isActive', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$settings || empty($settings->waba_id) || empty($settings->access_token)) {
            return [];
        }

        try {
            $response = Http::timeout(30)
                ->withToken($settings->access_token)
                ->get("https://graph.facebook.com/v21.0/{$settings->waba_id}/subscribed_apps");

            if ($response->successful()) {
                return $response->json('data') ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Failed to list WABA subscribed apps: ' . $e->getMessage());
        }

        return [];
    }
}
