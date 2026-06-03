<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UserProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'first_name' => 'sometimes|nullable|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'sometimes|nullable|string|max:100',
            'photo' => 'nullable|image|max:4096',
            'age' => 'sometimes|nullable|integer|min:16|max:120',
            'country' => 'sometimes|nullable|string|max:100',
            'city' => 'sometimes|nullable|string|max:100',
        ];
    }
}
