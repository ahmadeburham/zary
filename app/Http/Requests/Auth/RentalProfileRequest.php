<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RentalProfileRequest extends FormRequest
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
            'type' => 'required|string|in:student,employee,other,prefer_not_to_say',
            'university_id' => 'nullable|uuid|exists:universities,id',
            'university_program_id' => 'nullable|uuid|exists:university_programs,id',
            'university' => 'nullable|string|max:255',
            'faculty' => 'nullable|string|max:255',
            'major' => 'nullable|string|max:255',
            'budget_min' => 'nullable|numeric|min:0',
            'budget_max' => 'nullable|numeric|min:0',
            'preferred_location' => 'nullable|string|max:100',
            'prefers_furnished' => 'nullable|boolean',
            'company' => 'required_if:type,employee|nullable|string',
            'job_title' => 'required_if:type,employee|nullable|string',
        ];
    }
}
