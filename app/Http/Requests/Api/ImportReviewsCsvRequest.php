<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ImportReviewsCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'product_column' => ['nullable', 'string'],
            'rating_column' => ['nullable', 'string'],
            'text_column' => ['nullable', 'string'],
            'has_header' => ['nullable', 'boolean'],
            'queue' => ['nullable', 'boolean'],
        ];
    }
}


