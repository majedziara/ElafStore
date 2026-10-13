<?php

namespace App\Http\Requests;

use App\Support\AdminResources;
use Illuminate\Foundation\Http\FormRequest;

class AdminResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $d = AdminResources::definition($this->route('resource'));

        return (bool) $this->user()?->can($d[1]);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->code))]);
        }
    }

    public function rules(): array
    {
        return AdminResources::definition($this->route('resource'), $this->route('id') ? (int) $this->route('id') : null)[2];
    }
}
