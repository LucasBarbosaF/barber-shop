<?php

/**
 * Architectural guardrails (ratchet) for the Domain/Application/Infrastructure split.
 *
 * These tests deliberately do NOT refactor existing violations. They freeze the
 * known debt so it can only shrink. When a violation is fixed, remove the
 * corresponding entry from the frozen list in the same commit.
 *
 * Reference: docs/sprint-0-discovery.md, section 6.
 */
arch('domain does not depend on delivery or the service container')
    ->expect('App\Domain')
    ->not->toUse([
        'App\Http',
        'App\Infrastructure',
        'Illuminate\Foundation',
        'Illuminate\Http',
        'Illuminate\Routing',
        'Illuminate\Session',
        'Illuminate\Support\Facades',
    ]);

arch('domain shared kernel is framework free')
    ->expect('App\Domain\Shared')
    ->not->toUse('Illuminate');

arch('domain enums are framework free')
    ->expect('App\Domain\Tenant\Enums')
    ->not->toUse('Illuminate');

arch('domain does not depend on the legacy app models namespace')
    ->expect('App\Domain\Shared')
    ->not->toUse('App\Models');

arch('use cases do not depend on http delivery')
    ->expect('App\Application')
    ->not->toUse([
        'App\Http',
        'Illuminate\Http',
        'Illuminate\Routing',
    ]);

arch('infrastructure does not reach back into http delivery')
    ->expect('App\Infrastructure')
    ->not->toUse('App\Http');

it('freezes the single call site of the role to permission matrix', function (): void {
    /*
     * A Sprint 4 liga Gate, Policy e middleware ao mesmo `PermissionChecker`.
     * O risco de uma integração não é ela errar — é alguém escrever a
     * autorização de novo por baixo dela, num `in_array($role, ...)` jogado
     * dentro de um controller. A partir daí existem duas matrizes e a segunda
     * não recebe atualização de role.
     *
     * `RolePermissions::for()` é a matriz; quem pode chamá-la é o `PermissionChecker`
     * e mais ninguém. Para liberar um novo ponto, o certo é perguntar se ele
     * deveria passar pelo checker — não acrescentar o arquivo a esta lista.
     */
    $allowed = [
        'Application/Authorization/PermissionChecker.php',
    ];

    $found = [];

    foreach (app_php_files() as $file) {
        $source = (string) file_get_contents($file);

        if (str_contains($source, 'RolePermissions::')) {
            $found[] = relative_app_path($file);
        }
    }

    sort($found);

    expect($found)->toBe($allowed);
});

it('freezes the classes that resolve a permission by hand', function (): void {
    /*
     * Mesmo princípio, agora para o enum. Um `Permission::UsersView` comparado
     * direto com uma role, sem o checker, é autorização reimplementada com o
     * nome certo e a regra errada.
     *
     * A lista cobre quem *decide*: os que traduzem role em permissão ou leem o
     * TenantContext para autorizar. Policy e middleware podem citar o enum
     * porque passam pelo `PermissionChecker` — o que não pode é refazer a conta.
     */
    $allowed = [
        'Domain/Authorization/RolePermissions.php',
        'Http/Middleware/EnsurePermission.php',
        'Policies/BarberPolicy.php',
        'Policies/CustomerPolicy.php',
        'Policies/MembershipPolicy.php',
        'Policies/ServicePolicy.php',
        'Providers/AuthorizationServiceProvider.php',
    ];

    $found = [];

    foreach (app_php_files() as $file) {
        $source = (string) file_get_contents($file);

        if (str_contains($source, 'Permission::')) {
            $found[] = relative_app_path($file);
        }
    }

    sort($found);

    expect($found)->toBe($allowed);
});

it('freezes the middleware that reads the tenant outside the resolver', function (): void {
    /*
     * Só o `ResolveTenant` pode ler o TenantContext para *preencher* o tenant da
     * requisição. `EnsureTenantSelected` e `EnsurePermission` o leem para
     * conferir — o que é correto. O que não entra aqui é qualquer terceiro
     * middleware decidindo barbearia por conta própria.
     *
     * `SetTenantDatabaseContext` entrou na lista na Sprint 5: ele lê o contexto
     * para escrever `app.current_tenant` no banco. Não decide nada — apenas
     * informa ao RLS a barbearia que o `ResolveTenant` já decidiu.
     */
    $allowed = [
        'Http/Middleware/EnsurePermission.php',
        'Http/Middleware/EnsureTenantSelected.php',
        'Http/Middleware/ResolveTenant.php',
        'Http/Middleware/SetPublicBookingTenant.php',
        'Http/Middleware/SetTenantDatabaseContext.php',
    ];

    $found = [];

    foreach (app_php_files('Http/Middleware') as $file) {
        if (str_contains((string) file_get_contents($file), 'TenantContext')) {
            $found[] = relative_app_path($file);
        }
    }

    sort($found);

    expect($found)->toBe($allowed);
});

it('freezes the call sites of the tenant resolver', function (): void {
    /*
     * `TenantResolver` só funciona dentro do grupo `authenticated`. Ele decide o
     * tenant lendo as memberships do usuário, e essa leitura é uma query whose
     * visible rows dependem de `app.current_user` — a opção que
     * `SetUserDatabaseContext` escreve antes do `ResolveTenant` rodar.
     *
     * Sem ela a consulta volta vazia e o resolver devolve `None`. É falha
     * fechada, então não vira brecha: vira "esta pessoa não pertence a nenhuma
     * barbearia", numa tela que mente. Job de fila, comando de console e
     * listener são exatamente os lugares onde isso aconteceria em silêncio, já
     * que nenhum deles passa pelo middleware.
     *
     * Para incluir um chamador, a pergunta não é "ele pode chamar?" e sim
     * "ele declara o usuário no banco?". Se a resposta for não, ele declara —
     * ou deixa de ser um ponto de resolução de tenant.
     *
     * O padrão casa **uso**, não menção: três arquivos citam `TenantResolver` em
     * comentário, e um ratchet que se dispara com prosa é um ratchet que se
     * desliga no primeiro `// TenantResolver` escrito por engano.
     */
    $allowed = [
        'Application/Shared/Tenancy/TenantResolver.php',
        'Http/Controllers/App/TenantController.php',
        'Http/Middleware/ResolveTenant.php',
    ];

    $usage = '/TenantResolver::class|\bresolveFor\(|\bswitchTo\(/';

    $found = [];

    foreach (app_php_files() as $file) {
        if (preg_match($usage, (string) file_get_contents($file)) === 1) {
            $found[] = relative_app_path($file);
        }
    }

    sort($found);

    expect($found)->toBe($allowed);
});

it('freezes the known domain persistence debt', function (): void {
    $frozen = [
        'Domain/Tenant/Models/Membership.php',
        'Domain/Tenant/Models/Tenant.php',
    ];

    $persistenceNamespaces = [
        'App\Models',
        'Illuminate\Database',
    ];

    $offenders = files_importing('Domain', $persistenceNamespaces);

    expect($offenders)->toBe($frozen);
});

it('freezes the set of classes extending a persistence base class', function (): void {
    $frozen = [
        'Domain/Tenant/Models/Membership.php',
        'Domain/Tenant/Models/Tenant.php',
        'Infrastructure/Shared/Persistence/TenantScopedModel.php',
        'Models/Appointment.php',
        'Models/AppointmentNotification.php',
        'Models/Attendance.php',
        'Models/AttendanceItem.php',
        'Models/Barber.php',
        'Models/BlockedPeriod.php',
        'Models/BusinessHour.php',
        'Models/CashRegister.php',
        'Models/CashTransaction.php',
        'Models/Commission.php',
        'Models/CommissionPayment.php',
        'Models/CommissionPeriod.php',
        'Models/CommissionRule.php',
        'Models/Customer.php',
        'Models/Payment.php',
        'Models/PaymentEvent.php',
        'Models/PaymentRefund.php',
        'Models/Service.php',
        'Models/User.php',
    ];

    $pattern = '/\bclass\s+\w+\s+extends\s+(Model|TenantScopedModel|Authenticatable|PersonalAccessToken)\b/';

    $found = [];

    foreach (app_php_files() as $file) {
        if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
            $found[] = relative_app_path($file);
        }
    }

    sort($found);

    expect($found)->toBe($frozen);
});

/**
 * @return array<int, string>
 */
function app_php_files(string $subdirectory = ''): array
{
    $root = dirname(__DIR__, 2).'/app'.($subdirectory === '' ? '' : '/'.$subdirectory);

    if (! is_dir($root)) {
        return [];
    }

    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function relative_app_path(string $path): string
{
    return ltrim(str_replace(dirname(__DIR__, 2).'/app/', '', $path), '/');
}

/**
 * @param  array<int, string>  $namespaces
 * @return array<int, string>
 */
function files_importing(string $subdirectory, array $namespaces): array
{
    $matches = [];

    foreach (app_php_files($subdirectory) as $file) {
        $offends = false;

        foreach (imported_namespaces($file) as $import) {
            foreach ($namespaces as $namespace) {
                if (str_starts_with($import, $namespace)) {
                    $offends = true;

                    break 2;
                }
            }
        }

        if ($offends) {
            $matches[] = relative_app_path($file);
        }
    }

    sort($matches);

    return array_values($matches);
}

/**
 * @return array<int, string>
 */
function imported_namespaces(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));

    $depth = 0;
    $imports = [];
    $total = count($tokens);

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];

        if ($token === '{') {
            $depth++;

            continue;
        }

        if ($token === '}') {
            $depth--;

            continue;
        }

        if (! is_array($token) || $token[0] !== T_USE || $depth !== 0) {
            continue;
        }

        $import = '';

        for ($i++; $i < $total; $i++) {
            $next = $tokens[$i];

            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (! is_array($next)) {
                break;
            }

            if (! in_array($next[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                break;
            }

            $import .= $next[1];
        }

        if ($import !== '') {
            $imports[] = ltrim($import, '\\');
        }
    }

    return $imports;
}
