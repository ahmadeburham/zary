<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'phone' => 'required_without:email|nullable|string|unique:users,phone',
            'email' => 'required_without:phone|nullable|email|unique:users,email',
            'password' => 'required|string|min:8',
            'gender' => 'required|string|in:male,female',
            'role' => 'required|string|in:rental,owner,sponsor',
        ];
    }
}
