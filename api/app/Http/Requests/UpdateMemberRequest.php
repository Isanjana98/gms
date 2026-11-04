<?php

namespace App\Http\Requests;

use App\Enums\MemberStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** SRS 4 - Validation for editing a member. */
class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MEMBERS_UPDATE);
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'trainer_id' => ['nullable', 'integer', 'exists:trainers,id'],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'nic_passport' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['MALE', 'FEMALE', 'OTHER'])],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_number' => ['nullable', 'string', 'max:30'],
            'join_date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(MemberStatus::values())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
