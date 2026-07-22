<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PackageController extends Controller
{
    /**
     * Get all packages
     */
    public function index(Request $request)
    {
        try {
            $packages = Package::orderBy('packageName', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $packages
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new package (admin only)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'packageName' => 'required|string|max:255',
            'packageDesc' => 'nullable|string',
            'day' => 'required|integer|min:1',
            'msgCount' => 'required|integer|min:1',
            'templateCount' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $package = Package::create($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Package created successfully',
                'data' => $package
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific package
     */
    public function show(Request $request, $id)
    {
        try {
            $package = Package::find($id);

            if (!$package) {
                return response()->json([
                    'status' => false,
                    'message' => 'Package not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $package
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a package (admin only)
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'packageName' => 'sometimes|string|max:255',
            'packageDesc' => 'nullable|string',
            'day' => 'sometimes|integer|min:1',
            'msgCount' => 'sometimes|integer|min:1',
            'templateCount' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $package = Package::find($id);

            if (!$package) {
                return response()->json([
                    'status' => false,
                    'message' => 'Package not found'
                ], 404);
            }

            $package->update($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Package updated successfully',
                'data' => $package
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a package (admin only)
     */
    public function destroy(Request $request, $id)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $package = Package::find($id);

            if (!$package) {
                return response()->json([
                    'status' => false,
                    'message' => 'Package not found'
                ], 404);
            }

            $package->delete();

            return response()->json([
                'status' => true,
                'message' => 'Package deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
