<?php

namespace App\Http\Requests;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pricing = app(PixelforgeClient::class)->pricing();

        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most 4 reference images.',
            'reference_images.*.required' => 'Fill in or remove the empty reference image.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ];
    }
}
