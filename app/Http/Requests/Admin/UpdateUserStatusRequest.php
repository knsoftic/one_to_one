<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            // Bans have their own form (length and reason).
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE, User::STATUS_SUSPENDED])],
        ];
    }
}
