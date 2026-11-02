<?php

namespace App\Http\Requests;

use App\MessageChannel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'required_without:image', 'string', 'max:4000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,gif,webp', 'max:5120'],
            'channel' => ['required', Rule::enum(MessageChannel::class)],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
        ];
    }
}
