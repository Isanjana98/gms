<?php

namespace App\Http\Requests;

use App\Enums\MemberStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** SRS 4 - Validation for creating a member. */
class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MEMBERS_CREATE);
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'trainer_id' => ['nullable', 'integer', 'exists:trainers,id'],
            'full_name' => ['required', 'string', 'max:255'],
            // Not unique: families share identity documents in practice, and a
            // hard constraint here blocks legitimate registrations at the desk.
            // Duplicates are surfaced as a warning instead.
            'nic_passport' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['MALE', 'FEMALE', 'OTHER'])],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_number' => ['nullable', 'string', 'max:30'],
            'join_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(MemberStatus::values())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],

            // Optional: sell a membership in the same request as registration,
            // which is how reception actually works (SRS 32 steps 1-3).
            'membership_plan_id' => ['nullable', 'integer', 'exists:membership_plans,id'],
            'membership_start_date' => ['nullable', 'date'],
            'membership_discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'The member\'s full name is required.',
            'date_of_birth.before' => 'The date of birth must be in the past.',
        ];
    }
}
