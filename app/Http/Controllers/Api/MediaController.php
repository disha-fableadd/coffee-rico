<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMediaRequest;
use App\Http\Requests\UpdateMediaRequest;
use App\Models\Media;
use App\Support\UploadPath;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $query = Media::query();

            // Filter by type (image, video, document, etc.)
            if ($request->has('type')) {
                if ($request->type === 'image') {
                    $query->where('mime_type', 'like', 'image/%');
                } elseif ($request->type === 'video') {
                    $query->where('mime_type', 'like', 'video/%');
                } elseif ($request->type === 'document') {
                    $query->where('mime_type', 'not like', 'image/%')
                          ->where('mime_type', 'not like', 'video/%');
                }
            }

            // Search by name or description
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Get all media without pagination
            $media = $query->orderBy('created_at', 'desc')->get();

            // Add full URLs to all media items (same as profile images)
            $media->transform(function ($item) {
                $item->full_url = $item->getFullUrlAttribute();
                return $item;
            });

            return response()->json([
                'success' => true,
                'data' => $media
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMediaRequest $request)
    {
        try {

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $mimeType = $file->getMimeType();
            $size = $file->getSize();

            // Generate unique filename
            $filename = time() . '_' . Str::uuid() . '.' . $extension;

            // Create directory if it doesn't exist (following profile image pattern)
            $mediaPath = public_path('uploads/media');
            if (!file_exists($mediaPath)) {
                mkdir($mediaPath, 0755, true);
            }

            // Move file to storage (following profile image pattern)
            $file->move($mediaPath, $filename);

            $storedPath = UploadPath::store('uploads/media/' . $filename);

            // Create media record
            $media = Media::create([
                'name' => $originalName,
                'filename' => $filename,
                'path' => $storedPath,
                'url' => $storedPath, // Store the same path as URL (like profile images)
                'mime_type' => $mimeType,
                'size' => $size,
                'extension' => $extension,
                'description' => $request->description,
                'alt_text' => $request->alt_text,
            ]);

            // Add full URL to response (same as profile images)
            $media->full_url = $media->getFullUrlAttribute();
            return response()->json([
                'success' => true,
                'message' => 'Media file uploaded successfully',
                'data' => $media
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload media file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $media = Media::findOrFail($id);
            // Add full URL to response (same as profile images)
            $media->full_url = $media->getFullUrlAttribute();
            return response()->json([
                'success' => true,
                'data' => $media
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Media file not found'
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMediaRequest $request, string $id)
    {
        try {
            $media = Media::findOrFail($id);

            $media->update([
                'description' => $request->description,
                'alt_text' => $request->alt_text,
            ]);

            // Add full URL to response (same as profile images)
            $media->full_url = $media->getFullUrlAttribute();
            return response()->json([
                'success' => true,
                'message' => 'Media file updated successfully',
                'data' => $media
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update media file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $media = Media::findOrFail($id);
            $media->delete(); // This will also delete the physical file due to the model's delete method

            return response()->json([
                'success' => true,
                'message' => 'Media file deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete media file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get media statistics.
     */
    public function stats()
    {
        try {
            $totalMedia = Media::count();
            $totalSize = Media::sum('size');
            $imageCount = Media::where('mime_type', 'like', 'image/%')->count();
            $videoCount = Media::where('mime_type', 'like', 'video/%')->count();
            $documentCount = Media::where('mime_type', 'not like', 'image/%')
                                 ->where('mime_type', 'not like', 'video/%')
                                 ->count();

            $stats = [
                'total_media' => $totalMedia,
                'total_size' => $totalSize,
                'human_size' => $this->formatBytes($totalSize),
                'image_count' => $imageCount,
                'video_count' => $videoCount,
                'document_count' => $documentCount,
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve media statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format bytes to human readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
