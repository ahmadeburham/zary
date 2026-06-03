<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SocialLoginRequest extends FormRequest
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
            'provider' => 'required|string|in:google,facebook',
            'provider_id' => 'required|string',
            'email' => 'required|email',
            'name' => 'nullable|string',
            'role' => 'nullable|string|in:rental,owner,sponsor',
        ];
    }
}
