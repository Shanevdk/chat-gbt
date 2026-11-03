<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompanionUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'personality' => ['required', 'string', 'max:2000'],
            'appearance' => ['nullable', 'string', 'max:1000'],
            'voice_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
