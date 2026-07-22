<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivePackage;
use App\Support\UploadPath;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    use ApiResponse;
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'number' => 'required|string|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'sometimes|string|in:user,admin',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'number' => $request->number,
                'password' => Hash::make($request->password),
                'role' => $request->role ?? 'user',
                'profileImage' => $request->hasFile('profileImage')
                    ? UploadPath::store('uploads/profiles/' . $request->file('profileImage')->getClientOriginalName())
                    : UploadPath::store('images/default-avatar.svg')
            ]);

            $token = $user->createToken('API Token')->accessToken;

            return response()->json([
                'status' => true,
                'message' => 'Registration successful',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'number' => $user->number,
                    'role' => $user->role,
                    'profileImage' => $user->profile_image_url,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
                ],
                'token' => $token
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login user
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Email and password are required'
            ], 400);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if ($user->status !== 'active') {
                return response()->json([
                    'status' => false,
                    'message' => 'Your account is not active. Please contact support.'
                ], 403);
            }

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid email or password'
                ], 401);
            }

            $token = $user->createToken('API Token')->accessToken;

            return response()->json([
                'status' => true,
                'message' => 'Login successful',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'number' => $user->number,
                    'profileImage' => $user->profile_image_url,
                    'status' => $user->status,
                    'isloggedIn' => true,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Server error during login'
            ], 500);
        }
    }

    /**
     * Get user profile
     */
    public function getProfile(Request $request)
    {
        try {
            $user = $request->user();

            // Get global active package (first active package from any user)
            $activePackage = ActivePackage::where('status', 1)
                ->with('package')
                ->latest()
                ->first();

            $profileData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'number' => $user->number,
                'role' => $user->role,
                'status' => $user->status,
                'bio' => $user->bio,
                'companyName' => $user->companyName,
                'companyLogo' => $user->company_logo_url,
                'companyAddress' => $user->companyAddress,
                'companyEmail' => $user->companyEmail,
                'companyMobile' => $user->companyMobile,
                'profileImage' => $user->profile_image_url,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'activePackage' => $activePackage ? [
                    'id' => $activePackage->id,
                    'packageName' => $activePackage->package->packageName ?? null,
                    'packageDesc' => $activePackage->package->packageDesc ?? null,
                    'day' => $activePackage->package->day ?? null,
                    'msgCount' => $activePackage->package->msgCount ?? null,
                    'templateCount' => $activePackage->package->templateCount ?? null,
                    'contactCount' => $activePackage->package->contactCount ?? null,
                    'startDate' => $activePackage->startDate,
                    'endDate' => $activePackage->endDate,
                    'status' => $activePackage->status,
                    'lastMonthlyReset' => $activePackage->lastMonthlyReset,
                    'nextRenewalDate' => $activePackage->lastMonthlyReset ? $activePackage->lastMonthlyReset->addDays(30) : null,
                    'daysUntilRenewal' => $activePackage->lastMonthlyReset ? now()->diffInDays($activePackage->lastMonthlyReset->addDays(30), false) : null,
                    'usage' => [
                        'monthlyUsedMessages' => $activePackage->monthlyUsedMsgCount ?? 0,
                        'monthlyUsedTemplates' => $activePackage->monthlyUsedTemplateCount ?? 0,
                        'monthlyUsedContacts' => $activePackage->monthlyUsedContactCount ?? 0,
                        'totalUsedMessages' => $activePackage->usedMsgCount ?? 0,
                    ],
                    'limits' => [
                        'messageLimit' => $activePackage->package->msgCount ?? 0,
                        'templateLimit' => $activePackage->package->templateCount ?? 0,
                        'contactLimit' => $activePackage->package->contactCount ?? 0,
                    ]
                ] : null
            ];

            // Add debug information
            \Log::info("Profile Request", [
                'userId' => $user->id,
                'userName' => $user->name,
                'activePackageId' => $activePackage ? $activePackage->id : null,
                'packageName' => $activePackage ? $activePackage->package->packageName : null
            ]);

            return response()->json([
                'status' => true,
                'user' => $profileData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test file upload (for debugging)
     */
    public function testFileUpload(Request $request)
    {
        $debugInfo = [
            'method' => $request->method(),
            'has_test_file' => $request->hasFile('testFile'),
            'has_profile_image' => $request->hasFile('profileImage'),
            'all_files' => $request->allFiles(),
            'files_bag' => $request->files->all(),
            'content_type' => $request->header('Content-Type'),
            'all_input' => $request->all()
        ];

        if ($request->hasFile('testFile')) {
            $file = $request->file('testFile');
            $debugInfo['file_info'] = [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'extension' => $file->getClientOriginalExtension(),
                'is_valid' => $file->isValid(),
                'error' => $file->getError(),
                'error_message' => $file->getErrorMessage()
            ];
        }

        return response()->json([
            'status' => true,
            'debug' => $debugInfo
        ]);
    }

    /**
     * Update user profile
     */
    public function editProfile(Request $request)
    {
        // Handle both PUT and POST requests
        if ($request->isMethod('put')) {
            // For PUT requests with file uploads, we need to handle this differently
            // Laravel doesn't handle file uploads well with PUT requests
            // We'll use a workaround by checking if files exist in the request
            $files = $request->allFiles();
            if (!empty($files)) {
                // If files are present, we need to handle them manually
                foreach ($files as $key => $file) {
                    $request->files->set($key, $file);
                }
            }
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $request->user()->id,
            'number' => 'nullable|string|unique:users,number,' . $request->user()->id,
            'bio' => 'nullable|string',
            'companyName' => 'nullable|string',
            'companyAddress' => 'nullable|string',
            'companyEmail' => 'nullable|email',
            'companyMobile' => 'nullable|string',
            'profileImage' => 'nullable',
            'companyLogo' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $user = $request->user();


            $user->update($request->only([
                'name', 'email', 'number', 'bio',
                'companyName', 'companyAddress', 'companyEmail', 'companyMobile'
            ]));

            // Handle profile image upload
            if ($request->hasFile('profileImage')) {
                $profileImage = $request->file('profileImage');


                // Manual file type validation
                $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/svg+xml', 'image/webp'];
                $mimeType = $profileImage->getMimeType();

                if (!in_array($mimeType, $allowedTypes)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid file type. Only JPEG, PNG, JPG, GIF, and SVG files are allowed.',
                        'detected_type' => $mimeType
                    ], 400);
                }

                $filename = time() . '_' . $user->id . '_profile.' . $profileImage->getClientOriginalExtension();

                // Create directory if it doesn't exist
                $profilePath = public_path('uploads/profiles');
                if (!file_exists($profilePath)) {
                    mkdir($profilePath, 0755, true);
                }

                // Delete old profile image if exists
                UploadPath::delete($user->profileImage);

                // Move file to storage
                $profileImage->move($profilePath, $filename);
                $user->profileImage = UploadPath::store('uploads/profiles/' . $filename);
            }

            // Handle company logo upload
            if ($request->hasFile('companyLogo')) {
                $companyLogo = $request->file('companyLogo');

                // Manual file type validation
                $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/svg+xml', 'image/webp'];
                $mimeType = $companyLogo->getMimeType();

                if (!in_array($mimeType, $allowedTypes)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid file type. Only JPEG, PNG, JPG, GIF, and SVG files are allowed.',
                        'detected_type' => $mimeType
                    ], 400);
                }

                $filename = time() . '_' . $user->id . '_company.' . $companyLogo->getClientOriginalExtension();

                // Create directory if it doesn't exist
                $logoPath = public_path('uploads/company');
                if (!file_exists($logoPath)) {
                    mkdir($logoPath, 0755, true);
                }

                // Delete old company logo if exists
                UploadPath::delete($user->companyLogo);

                // Move file to storage
                $companyLogo->move($logoPath, $filename);
                $user->companyLogo = UploadPath::store('uploads/company/' . $filename);
            }

            $user->save();

            // Refresh the user object to get updated values
            $user->refresh();

            return response()->json([
                'status' => true,
                'message' => 'Profile updated successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'number' => $user->number,
                    'bio' => $user->bio,
                    'companyName' => $user->companyName,
                    'companyLogo' => $user->company_logo_url,
                    'companyAddress' => $user->companyAddress,
                    'companyEmail' => $user->companyEmail,
                    'companyMobile' => $user->companyMobile,
                    'profileImage' => $user->profile_image_url,
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
     * Change password
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8',
            'confirmPassword' => 'required|string|same:newPassword',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $user = $request->user();

            if (!Hash::check($request->input('currentPassword'), $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Incorrect current password'
                ], 401);
            }

            $user->password = Hash::make($request->input('newPassword'));
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'Password changed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->token()->revoke();

            return response()->json([
                'status' => true,
                'message' => 'Successfully logged out'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
