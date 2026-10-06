<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Troca de barbearia.
 *
 * A validação de formato é só a primeira linha: `exists:tenants` impede lixo,
 * mas quem decide se a troca é legítima é o `TenantResolver`, que exige
 * membership ativa. Um tenant existente e alheio passa pelo `exists` e morre no
 * resolver com 403 — que é o comportamento correto.
 */
class SwitchTenantRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tenant_id' => 'barbearia',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tenant_id.required' => 'Escolha uma barbearia.',
            'tenant_id.exists' => 'Esta barbearia não existe.',
        ];
    }

    public function tenantId(): string
    {
        return (string) $this->validated('tenant_id');
    }
}
