<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeHandleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'handle' => ['required', 'string', 'min:1', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}


