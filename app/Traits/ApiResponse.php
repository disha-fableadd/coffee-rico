<?php

namespace App\Traits;

trait ApiResponse
{
    /**
     * Return a success JSON response
     */
    protected function success($data = null, $message = 'Success', $code = 200)
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     * Return an error JSON response
     */
    protected function error($message = 'Error', $code = 400, $errors = null)
    {
        $response = [
            'status' => false,
            'message' => $message
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Return a validation error JSON response
     */
    protected function validationError($errors, $message = 'Validation failed')
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'errors' => $errors
        ], 422);
    }

    /**
     * Return an unauthorized JSON response
     */
    protected function unauthorized($message = 'Unauthorized')
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => 'Authentication required'
        ], 401);
    }

    /**
     * Return a not found JSON response
     */
    protected function notFound($message = 'Resource not found')
    {
        return response()->json([
            'status' => false,
            'message' => $message
        ], 404);
    }

    /**
     * Return a forbidden JSON response
     */
    protected function forbidden($message = 'Forbidden')
    {
        return response()->json([
            'status' => false,
            'message' => $message
        ], 403);
    }

    /**
     * Return a server error JSON response
     */
    protected function serverError($message = 'Internal Server Error')
    {
        return response()->json([
            'status' => false,
            'message' => $message
        ], 500);
    }
}
