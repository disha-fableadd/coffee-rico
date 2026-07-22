<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Allow authenticated users to upload media
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,svg,webp,bmp,tiff,mp4,avi,mov,wmv,flv,webm,mkv|max:51200', // 50MB max for videos
            'description' => 'nullable|string|max:1000',
            'alt_text' => 'nullable|string|max:255',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A file is required.',
            'file.file' => 'The uploaded file must be a valid file.',
            'file.mimes' => 'The file must be an image (jpeg, png, jpg, gif, svg, webp, bmp, tiff) or video (mp4, avi, mov, wmv, flv, webm, mkv).',
            'file.max' => 'The file size must not exceed 50MB.',
            'description.max' => 'The description must not exceed 1000 characters.',
            'alt_text.max' => 'The alt text must not exceed 255 characters.',
        ];
    }
}
