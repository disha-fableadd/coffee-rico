<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\BulkMessageController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\ActivePackageController;
use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::get('/', function () {
    return response()->json(['message' => 'Backend is running 🚀']);
});
Route::get('/redirectwhatsapp', function () {
    $number = env('WHATSAPP_CONTACT_US_NUMBER') ?? '0';
    $url = 'https://wa.me/'.$number;
    return redirect()->away($url);
});
// Cache management routes (public for development)
Route::get('/clear-cache', function () {
    try {
        $cleared = [];

        // Clear application cache
        \Artisan::call('cache:clear');
        $cleared['application_cache'] = 'Application cache cleared';

        // Clear route cache
        \Artisan::call('route:clear');
        $cleared['route_cache'] = 'Route cache cleared';

        // Clear config cache
        \Artisan::call('config:clear');
        $cleared['config_cache'] = 'Config cache cleared';

        // Clear view cache only if views directory exists
        if (is_dir(resource_path('views'))) {
            \Artisan::call('view:clear');
            $cleared['view_cache'] = 'View cache cleared';
        } else {
            $cleared['view_cache'] = 'Views directory not found, skipping view cache clear';
        }

        // Clear optimization cache
        \Artisan::call('optimize:clear');
        $cleared['optimization_cache'] = 'Optimization cache cleared';

        return response()->json([
            'status' => true,
            'message' => 'All caches cleared successfully',
            'cleared' => $cleared
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Error clearing caches: ' . $e->getMessage()
        ], 500);
    }
});

// Simple migrate route (use cautiously; consider protecting in production)
Route::get('/migrate', function () {
    try {
        $output = \Artisan::call('migrate', ['--force' => true]);
        return response()->json([
            'status' => true,
            'message' => 'Migrations executed successfully',
            'artisan_output' => \Artisan::output(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Migration failed: ' . $e->getMessage(),
        ], 500);
    }
});

// Simple passport:install route (use cautiously; consider protecting in production)
Route::get('/passport-install', function () {
    try {
        $output = \Artisan::call('passport:install', ['--force' => true]);
        return response()->json([
            'status' => true,
            'message' => 'Passport installed successfully',
            'artisan_output' => \Artisan::output(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Passport installation failed: ' . $e->getMessage(),
        ], 500);
    }
});

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// WhatsApp webhook - same URL for both verification (GET) and messages (POST)
Route::match(['GET', 'POST'], '/whatsapp/webhook', [BulkMessageController::class, 'handleWebhook']);

// Signed conversation media (no auth required; valid signature allows direct link / <img> access)
Route::get('/conversations/media/signed/{conversationId}/{mediaId}', [ConversationController::class, 'serveSignedMedia'])
    ->name('conversations.media.signed');

// Public media routes (no authentication required)
Route::get('/media/{filename}', function($filename) {
    $filePath = public_path('uploads/media/' . $filename);

    if (!file_exists($filePath)) {
        return response()->json(['error' => 'File not found'], 404);
    }

    $mimeType = mime_content_type($filePath);
    return response()->file($filePath, [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=31536000' // Cache for 1 year
    ]);
});

// Protected routes
Route::middleware('auth:api')->group(function () {
    // Auth routes
    Route::get('/auth/profile', [AuthController::class, 'getProfile']);
    Route::post('/auth/edit-profile', [AuthController::class, 'editProfile']);
    Route::put('/auth/edit-profile', [AuthController::class, 'editProfile']);
    Route::post('/auth/test-upload', [AuthController::class, 'testFileUpload']);
    Route::put('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Dashboard routes
    Route::get('/dashboard', [DashboardController::class, 'getDashboard']);

    // Contact routes
    Route::apiResource('contacts', ContactController::class);
    Route::post('/contacts/import', [ContactController::class, 'import']);
    Route::get('/contacts/limits', [ContactController::class, 'getLimits']);
    Route::post('/contacts/delete', [ContactController::class, 'removeContacts']);

    // Group routes
    Route::post('/groups', [GroupController::class, 'store']);
    Route::put('/groups/{id}', [GroupController::class, 'update']);
    Route::delete('/groups/{id}', [GroupController::class, 'destroy']);
    Route::get('/groups', [GroupController::class, 'index']);
    Route::get('/groups/{id}', [GroupController::class, 'show']);
    Route::post('/groups/assign', [GroupController::class, 'addContacts']);
    Route::post('/groups/remove', [GroupController::class, 'removeContacts']);

    // Template routes
    Route::get('/templates/sync-templates', [TemplateController::class, 'syncTemplates']);
    Route::get('/templates/user', [TemplateController::class, 'getUserTemplates']);
    Route::get('/templates/user/{templateId}', [TemplateController::class, 'getUserTemplateById']);
    Route::get('/templates/filtered', [TemplateController::class, 'getFilteredTemplates']);
    Route::get('/templates/status', [TemplateController::class, 'getTemplatesByStatus']);
    Route::get('/templates/limits', [TemplateController::class, 'getLimits']);
    Route::post('/templates/custom', [TemplateController::class, 'addCustomTemplate']);
    Route::put('/templates/custom/{templateId}', [TemplateController::class, 'editCustomTemplate']);
    Route::delete('/templates/custom/{templateId}', [TemplateController::class, 'deleteCustomTemplate']);
    Route::put('/templates/status/{templateId}', [TemplateController::class, 'updateStatus']);
    Route::post('/template/create', [TemplateController::class, 'createWhatsAppTemplate']);

    // Bulk Message routes
    Route::apiResource('bulkmessage', BulkMessageController::class);
    Route::post('/bulk-messages/{id}/update', [BulkMessageController::class, 'updateCampaign']);
    Route::get('/bulk-messages/{id}/stats', [BulkMessageController::class, 'getStats']);

    // Delivery status routes
    Route::get('/bulk-messages/{id}/delivery-status', [BulkMessageController::class, 'getDeliveryStatus']);
    Route::get('/bulk-messages/{id}/delivery-status/summary', [BulkMessageController::class, 'getDeliveryStatusSummary']);
    Route::get('/bulk-messages/{campaignId}/contact/{contactId}/delivery-status', [BulkMessageController::class, 'getContactDeliveryStatus']);
    Route::get('/bulk-messages/{id}/status', [BulkMessageController::class, 'getCampaignStatus']);
    Route::put('/bulk-messages/{id}/update-status', [BulkMessageController::class, 'updateCampaignStatusManually']);

    // Resend failed and pending messages
    Route::post('/bulk-messages/resend/{id}', [BulkMessageController::class, 'resendFailedAndPending']);

    // Batch processing settings routes
    Route::get('/batch-settings', [BulkMessageController::class, 'getBatchSettings']);
    Route::post('/batch-settings', [BulkMessageController::class, 'updateBatchSettings']);

    // Package routes
    Route::post('/package/add', [PackageController::class, 'store']);
    Route::get('/package/get', [PackageController::class, 'index']);
    Route::get('/package/get/{id}', [PackageController::class, 'show']);
    Route::put('/package/edit/{id}', [PackageController::class, 'update']);
    Route::delete('/package/delete/{id}', [PackageController::class, 'destroy']);

    // Active Package routes
    Route::post('/plan/create', [ActivePackageController::class, 'store']);
    Route::post('/plan/assign', [ActivePackageController::class, 'assignPackage']);
    Route::post('/plan/renew/{userId}', [ActivePackageController::class, 'renew']);
    Route::get('/plan/user/{userId}', [ActivePackageController::class, 'getUserPlan']);
    Route::get('/plan/history/{userId}', [ActivePackageController::class, 'getUserPlanHistory']);
    Route::get('/plan/renewal-status', [ActivePackageController::class, 'getRenewalStatus']);

    // Credit routes
    Route::post('/credits/create-order', [CreditController::class, 'createOrder']);
    Route::post('/credits/verify', [CreditController::class, 'verifyPayment']);
    Route::post('/credits/deduct', [CreditController::class, 'deductCredits']);
    Route::get('/credits/balance', [CreditController::class, 'getBalance']);
    Route::get('/credits/history', [CreditController::class, 'getHistory']);
    Route::get('/credits/report', [CreditController::class, 'creditReport']);

    // Reports routes
    Route::get('/reports', [ReportsController::class, 'index']);
    Route::get('/campaigns', [ReportsController::class, 'index1']);
    Route::get('/reports/campaign/{id}/delivery-status', [ReportsController::class, 'getCampaignDeliveryStatus']);

    // Settings routes
    Route::post('/settings/connect-facebook', [SettingsController::class, 'connectFacebook']);
    Route::post('/settings/refresh-token', [SettingsController::class, 'refreshToken']);
    Route::post('/settings/save-config', [SettingsController::class, 'saveSettings']);
    Route::get('/settings', [SettingsController::class, 'index']);

    // Notification routes
    Route::get('/notification', [NotificationController::class, 'index']);
    Route::post('/notification/mark-read', [NotificationController::class, 'markAsRead']);
    Route::post('/notification', [NotificationController::class, 'store']);
    Route::get('/notification/{id}', [NotificationController::class, 'show']);
    Route::put('/notification/{id}', [NotificationController::class, 'update']);
    Route::delete('/notification/{id}', [NotificationController::class, 'destroy']);
    Route::post('/notification/mark-all-read', [NotificationController::class, 'markAllAsRead']);

    // User routes
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users/add', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/edit/{id}', [UserController::class, 'update']);
    Route::delete('/users/delete/{id}', [UserController::class, 'destroy']);
    Route::put('/users/status/{id}', [UserController::class, 'updateStatus']);

    // Media routes
    Route::apiResource('media', MediaController::class);
    Route::get('/media/stats', [MediaController::class, 'stats']);

    // Conversation routes
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{id}', [ConversationController::class, 'show']);
    Route::get('/conversations/{conversationId}/media/{mediaId}', [ConversationController::class, 'downloadMedia']);
    Route::get('/conversations/latest/contacts', [ConversationController::class, 'getLatestContacts']);
    Route::get('/conversations/unread', [ConversationController::class, 'getUnreadConversations']);
    Route::put('/conversations/{id}/status', [ConversationController::class, 'updateStatus']);
    Route::get('/conversations/stats', [ConversationController::class, 'getStats']);

    // Message routes
    Route::post('/messages/send', [MessageController::class, 'sendMessage']);
    Route::get('/messages/conversation/{conversationId}', [MessageController::class, 'getMessages']);
    Route::post('/messages/{conversationId}/mark-read', [MessageController::class, 'markAsRead']);
    Route::get('/messages/eligible-conversations', [MessageController::class, 'getEligibleConversations']);
    Route::get('/messages/{conversationId}/check-eligibility', [MessageController::class, 'checkEligibility']);

    // Test route to compare URL generation
    Route::get('/test/media-url', function(Request $request) {
        $user = $request->user();
        $media = \App\Models\Media::first();

        return response()->json([
            'status' => true,
            'data' => [
                'profile_image_url' => $user->profile_image_url,
                'company_logo_url' => $user->company_logo_url,
                'media_url' => $media ? $media->getFullUrlAttribute() : 'No media found',
                'app_url' => config('app.url'),
                'is_production' => \App\Support\UploadPath::needsPublicPrefix()
            ]
        ]);
    });

    // Test route
    Route::get('/test/global-plan', function(Request $request) {
        $user = $request->user();
        $activePackage = \App\Models\ActivePackage::where('status', 1)
            ->with('package')
            ->latest()
            ->first();

        return response()->json([
            'status' => true,
            'data' => [
                'userId' => $user->id,
                'userName' => $user->name,
                'userRole' => $user->role,
                'globalActivePackage' => $activePackage ? [
                    'id' => $activePackage->id,
                    'packageName' => $activePackage->package->packageName,
                    'templateLimit' => $activePackage->package->templateCount,
                    'messageLimit' => $activePackage->package->msgCount,
                    'usedMessages' => \App\Models\ActivePackage::where('status', 1)->sum('usedMsgCount'),
                    'globalTemplateCount' => \App\Models\Template::where('type', 'custom')->count()
                ] : null
            ]
        ]);
    });
});
