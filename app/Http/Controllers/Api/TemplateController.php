<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\ActivePackage;
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class TemplateController extends Controller
{
    /**
     * Get all templates for the authenticated user
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $type = $request->query('type');

            $query = Template::where('userId', $userId);

            if ($type === 'custom') {
                $query->where('type', 'custom');
            } elseif ($type === 'system') {
                $query->where('type', 'system');
            }

            $templates = $query->orderBy('created_at', 'desc')->get();

            // Add isCustom and isRequest attributes to each template
            $templates->transform(function ($template) {
                $template->isCustom = $template->isCustom;
                $template->isRequest = $template->isRequest;
                return $template;
            });

            return response()->json([
                'status' => true,
                'count' => $templates->count(),
                'data' => $templates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new template
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|string|in:text,custom,system',
            'variables' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $userId = $request->user()->id;

            // Check if there's a global active package for custom templates
            if ($request->type === 'custom') {
                $activePackage = ActivePackage::where('status', 1)
                    ->with('package')
                    ->latest()
                    ->first();

                if (!$activePackage) {
                    return response()->json([
                        'status' => false,
                        'message' => 'No active plan found. Please purchase a plan first.'
                    ], 400);
                }

                $allowedTemplateCount = $activePackage->package->templateCount ?? 0;
                // Check global template count (all users combined)
                $currentTemplateCount = Template::where('type', 'custom')
                    ->count();

                // Debug information
                \Log::info("Template Limit Check", [
                    'userId' => $userId,
                    'userName' => $request->user()->name,
                    'packageName' => $activePackage->package->packageName,
                    'allowedTemplateCount' => $allowedTemplateCount,
                    'currentTemplateCount' => $currentTemplateCount,
                    'remainingTemplates' => $allowedTemplateCount - $currentTemplateCount
                ]);

                if ($currentTemplateCount >= $allowedTemplateCount) {
                    return response()->json([
                        'status' => false,
                        'message' => "You can only create {$allowedTemplateCount} custom templates as per your plan. You currently have {$currentTemplateCount} templates."
                    ], 403);
                }
            }

            // Check for duplicate template name
            $exists = Template::where('name', $request->name)
                ->where('userId', $userId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template name already exists for your account'
                ], 400);
            }

            $template = Template::create([
                'name' => $request->name,
                'content' => $request->content,
                'type' => $request->type,
                'variables' => $request->variables ?? [],
                'status' => 'active',
                'userId' => $userId
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Template created successfully',
                'data' => $template
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's template limits and usage
     */
    public function getLimits(Request $request)
    {
        try {
            $userId = $request->user()->id;

            // Get global active package
            $activePackage = ActivePackage::where('status', 1)
                ->with('package')
                ->latest()
                ->first();

            if (!$activePackage) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active plan found'
                ], 404);
            }

            // Get global template counts (all users combined)
            $customTemplateCount = Template::where('type', 'custom')
                ->count();

            $systemTemplateCount = Template::where('type', 'system')
                ->count();

            $allowedTemplateCount = $activePackage->package->templateCount ?? 0;
            $remainingTemplates = $allowedTemplateCount - $customTemplateCount;

            return response()->json([
                'status' => true,
                'data' => [
                    'package' => [
                        'name' => $activePackage->package->packageName,
                        'templateLimit' => $allowedTemplateCount,
                        'messageLimit' => $activePackage->package->msgCount,
                        'usedMessages' => ActivePackage::where('status', 1)->sum('monthlyUsedMsgCount'),
                        'remainingMessages' => $activePackage->package->msgCount - ActivePackage::where('status', 1)->sum('monthlyUsedMsgCount')
                    ],
                    'templates' => [
                        'custom' => [
                            'current' => $customTemplateCount,
                            'allowed' => $allowedTemplateCount,
                            'remaining' => $remainingTemplates
                        ],
                        'system' => [
                            'current' => $systemTemplateCount
                        ]
                    ]
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
     * Get a specific template
     */
    public function show(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $template = Template::where('userId', $userId)->find($id);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found'
                ], 404);
            }

            // Add isCustom and isRequest attributes
            $template->isCustom = $template->isCustom;
            $template->isRequest = $template->isRequest;

            return response()->json([
                'status' => true,
                'data' => $template
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a template
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'variables' => 'nullable|array',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $userId = $request->user()->id;
            $template = Template::where('userId', $userId)->find($id);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found'
                ], 404);
            }

            // Check for duplicate name if name is being updated
            if ($request->has('name')) {
                $exists = Template::where('name', $request->name)
                    ->where('id', '!=', $id)
                    ->where('userId', $userId)
                    ->exists();

                if ($exists) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Template name already exists'
                    ], 400);
                }
            }

            $template->update($request->only(['name', 'content', 'variables', 'status']));

            // Add isCustom and isRequest attributes
            $template->isCustom = $template->isCustom;
            $template->isRequest = $template->isRequest;

            return response()->json([
                'status' => true,
                'message' => 'Template updated successfully',
                'data' => $template
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a template
     */
    public function destroy(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $template = Template::where('userId', $userId)->find($id);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found'
                ], 404);
            }

            $template->delete();

            return response()->json([
                'status' => true,
                'message' => 'Template deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Update template status
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:PENDING,APPROVED,REJECTED'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $userId = $request->user()->id;
            $template = Template::where('userId', $userId)->find($id);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found'
                ], 404);
            }

            $template->update(['status' => $request->status]);

            // Add isCustom and isRequest attributes
            $template->isCustom = $template->isCustom;
            $template->isRequest = $template->isRequest;

            return response()->json([
                'status' => true,
                'message' => "Template status updated to '{$request->status}' successfully",
                'data' => $template
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user templates
     */
    public function getUserTemplates(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $templates = Template::where(function($query) use ($userId) {
                $query->where('userId', $userId)
                      ->orWhere('type', 'system');
            })->where('status', '!=', 'inactive')
              ->orderBy('created_at', 'desc')
              ->get();

            // Add isCustom and isRequest attributes to each template
            $templates->transform(function ($template) {
                $template->isCustom = $template->isCustom;
                $template->isRequest = $template->isRequest;
                return $template;
            });

            return response()->json([
                'status' => true,
                'count' => $templates->count(),
                'data' => $templates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get filtered templates
     */
    public function getFilteredTemplates(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $type = $request->query('type');
            $status = $request->query('status');
            $category = $request->query('category');

            $query = Template::where(function($q) use ($userId) {
                $q->where('userId', $userId)
                  ->orWhere('type', 'system');
            });

            if ($type) {
                $query->where('type', $type);
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($category) {
                $query->where('category', $category);
            }

            $templates = $query->orderBy('created_at', 'desc')->get();

            // Add isCustom and isRequest attributes to each template
            $templates->transform(function ($template) {
                $template->isCustom = $template->isCustom;
                $template->isRequest = $template->isRequest;
                return $template;
            });

            return response()->json([
                'status' => true,
                'count' => $templates->count(),
                'data' => $templates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add custom template
     */
    public function addCustomTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'language' => 'required|string',
            'category' => 'required|string',
            'components' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $userId = $request->user()->id;

            // Check if user has active package
            $activePackage = ActivePackage::where('userId', $userId)
                ->where('status', 1)
                ->with('package')
                ->first();

            if (!$activePackage) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active plan found. Please purchase a plan first.'
                ], 400);
            }

            $allowedTemplateCount = $activePackage->package->templateCount ?? 0;
            $currentTemplateCount = Template::where('userId', $userId)
                ->where('type', 'custom')
                ->count();

            if ($currentTemplateCount >= $allowedTemplateCount) {
                return response()->json([
                    'status' => false,
                    'message' => "You can only create {$allowedTemplateCount} custom templates as per your plan."
                ], 403);
            }

            // Check for duplicate template name
            $exists = Template::where('name', $request->name)
                ->where('userId', $userId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => "Template '{$request->name}' already exists for your account"
                ], 400);
            }

            // Create BODY component
            $components = [
                ['type' => 'BODY', 'text' => $request->components]
            ];

            $template = Template::create([
                'name' => $request->name,
                'language' => $request->language,
                'category' => $request->category,
                'content' => $request->components,
                'type' => 'custom',
                'status' => 'PENDING',
                'components' => $components,
                'userId' => $userId,
            ]);

            // Add isCustom and isRequest attributes
            $template->isCustom = $template->isCustom;
            $template->isRequest = $template->isRequest;

            return response()->json([
                'status' => true,
                'message' => 'Custom template added successfully',
                'data' => $template
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Edit custom template
     */
    public function editCustomTemplate(Request $request, $templateId)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'language' => 'required|string',
            'category' => 'required|string',
            'components' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $userId = $request->user()->id;

            $template = Template::where('userId', $userId)
                ->where('type', 'custom')
                ->find($templateId);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Custom template not found'
                ], 404);
            }

            // Check for duplicate template name (excluding current template)
            $exists = Template::where('name', $request->name)
                ->where('userId', $userId)
                ->where('id', '!=', $templateId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => "Template '{$request->name}' already exists for your account"
                ], 400);
            }

            // Update BODY component
            $components = [
                ['type' => 'BODY', 'text' => $request->components]
            ];

            $template->update([
                'name' => $request->name,
                'language' => $request->language,
                'category' => $request->category,
                'content' => $request->components,
                'components' => $components,
            ]);

            // Add isCustom and isRequest attributes
            $template->isCustom = $template->isCustom;
            $template->isRequest = $template->isRequest;

            return response()->json([
                'status' => true,
                'message' => 'Custom template updated successfully',
                'data' => $template
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete custom template
     */
    public function deleteCustomTemplate(Request $request, $templateId)
    {
        try {
            $userId = $request->user()->id;

            $template = Template::where('userId', $userId)
                ->where('type', 'custom')
                ->find($templateId);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Custom template not found'
                ], 404);
            }

            $template->delete();

            return response()->json([
                'status' => true,
                'message' => 'Custom template deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user template by ID
     */
    public function getUserTemplateById(Request $request, $templateId)
    {
        try {
            $userId = $request->user()->id;

            $template = Template::where(function($query) use ($userId) {
                $query->where('userId', $userId)
                      ->orWhere('type', 'system');
            })->find($templateId);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found'
                ], 404);
            }

            // Add isCustom and isRequest attributes
            $template->isCustom = $template->isCustom;
            $template->isRequest = $template->isRequest;

            return response()->json([
                'status' => true,
                'data' => $template
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync templates from WhatsApp Business API
     */
    public function syncTemplates(Request $request)
    {
        try {
            // Get active WhatsApp settings
            $settings = Settings::where('isActive', true)->first();

            if (!$settings) {
                return response()->json([
                    'status' => false,
                    'message' => 'Active WhatsApp settings not found'
                ], 404);
            }

            $wabaId = $settings->waba_id;
            $accessToken = $settings->access_token;

            if (!$wabaId || !$accessToken) {
                return response()->json([
                    'status' => false,
                    'message' => 'WhatsApp configuration incomplete'
                ], 400);
            }

            // Fetch templates from Facebook API
            $url = "https://graph.facebook.com/v18.0/{$wabaId}/message_templates?status=APPROVED";

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json'
            ])->get($url);

            if (!$response->successful()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to fetch templates from WhatsApp API',
                    'error' => $response->body()
                ], 400);
            }

            $templatesData = $response->json();
            $templates = $templatesData['data'] ?? [];

            if (empty($templates)) {
                return response()->json([
                    'status' => true,
                    'message' => 'No templates found',
                    'count' => 0
                ]);
            }

            $syncedCount = 0;

            // Save/update templates in database
            foreach ($templates as $templateData) {
                $template = Template::updateOrCreate(
                    [
                        'name' => $templateData['name'],
                        'language' => $templateData['language'] ?? 'en_US',
                    ],
                    [
                        'name' => $templateData['name'],
                        'language' => $templateData['language'] ?? 'en_US',
                        'category' => $templateData['category'] ?? 'UTILITY',
                        'status' => $templateData['status'] ?? 'APPROVED',
                        'components' => $templateData['components'] ?? [],
                        'type' => 'system',
                        'userId' => null, // System templates don't belong to specific users
                    ]
                );

                if ($template->wasRecentlyCreated || $template->wasChanged()) {
                    $syncedCount++;
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Templates synced successfully',
                'count' => $syncedCount,
                'total_fetched' => count($templates)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error syncing templates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create WhatsApp template
     */
    public function createWhatsAppTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'language' => 'required|string',
            'category' => 'required|string|in:UTILITY,MARKETING,AUTHENTICATION',
            'components' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // Get active WhatsApp settings
            $settings = Settings::where('isActive', true)->first();

            if (!$settings) {
                return response()->json([
                    'status' => false,
                    'message' => 'Active WhatsApp settings not found'
                ], 404);
            }

            $wabaId = $settings->waba_id;
            $accessToken = $settings->access_token;

            if (!$wabaId || !$accessToken) {
                return response()->json([
                    'status' => false,
                    'message' => 'WhatsApp configuration incomplete'
                ], 400);
            }

            // Create template via Facebook API
            $url = "https://graph.facebook.com/v18.0/{$wabaId}/message_templates";

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json'
            ])->post($url, [
                'name' => $request->name,
                'language' => $request->language,
                'category' => $request->category,
                'components' => $request->components
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to create template via WhatsApp API',
                    'error' => $response->body()
                ], 400);
            }

            $templateData = $response->json();

            // Save template to database
            $template = Template::create([
                'name' => $request->name,
                'language' => $request->language,
                'category' => $request->category,
                'status' => 'PENDING',
                'components' => $request->components,
                'type' => 'system',
                'userId' => null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'WhatsApp template created successfully',
                'data' => [
                    'template' => $template,
                    'whatsapp_response' => $templateData
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error creating WhatsApp template: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get templates by status
     */
    public function getTemplatesByStatus(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            $query = Template::query();

            if ($isAdmin) {
                // Admin can see all templates
                $query->where('status', 'PENDING')
                      ->where('isRequest', true);
            } else {
                // Regular users see their own templates
                $query->where('userId', $userId)
                      ->where(function($q) {
                          $q->where('status', 'PENDING')
                            ->orWhere('status', 'APPROVED')
                            ->orWhere('status', 'REJECTED');
                      });
            }

            $templates = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'status' => true,
                'count' => $templates->count(),
                'data' => $templates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
