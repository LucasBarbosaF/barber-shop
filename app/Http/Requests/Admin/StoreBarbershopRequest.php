<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBarbershopRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],

            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(User::class, 'email'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome da barbearia',
            'document' => 'CNPJ/CPF',
            'email' => 'e-mail da barbearia',
            'phone' => 'telefone da barbearia',
            'owner_name' => 'nome do responsável',
            'owner_email' => 'e-mail do responsável',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_email.unique' => 'Este e-mail já está cadastrado.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validatedData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'document' => $this->validated('document'),
            'email' => $this->validated('email'),
            'phone' => $this->validated('phone'),
            'owner_name' => (string) $this->validated('owner_name'),
            'owner_email' => (string) $this->validated('owner_email'),
        ];
    }
}
