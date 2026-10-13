<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->email))]);
        }
    }

    public function rules(): array
    {
        $email = ['required', 'email:rfc', 'max:254'];
        $password = ['required', 'confirmed', Password::min(12)->letters()->numbers()];

        return match ($this->route()->getActionMethod()) {
            'register' => ['name' => 'required|string|max:120', 'email' => [...$email, Rule::unique('users')], 'password' => $password, 'marketing_opt_in' => 'sometimes|boolean'],
            'login','adminLogin' => ['email' => $email, 'password' => 'required|string|max:255', 'remember' => 'sometimes|boolean'],
            'forgot' => ['email' => $email],
            'reset' => ['email' => $email, 'token' => 'required|string|max:255', 'password' => $password],
            'profile' => ['name' => 'required|string|max:120', 'marketing_opt_in' => 'sometimes|boolean'],
            'changeEmail' => ['email' => [...$email, Rule::unique('users')->ignore($this->user()?->id)], 'current_password' => 'required|string'],
            'changePassword' => ['current_password' => 'required|string', 'password' => $password],
            'consume' => ['token' => 'required|string|size:64'],
            default => [],
        };
    }
}
