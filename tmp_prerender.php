<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

$outDir = '/mnt/c/Users/ADM/AppData/Local/Temp/opencode/render';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

function save(string $name, string $html): void
{
    global $outDir;
    // Inline os CSS/JS do build para a página funcionar via file://
    $html = preg_replace_callback(
        '#<link rel="stylesheet" href="[^"]*/build/(assets/[^"]+\.css)"[^>]*>#',
        function ($m) {
            $p = public_path('build/'.$m[1]);
            if (! is_file($p)) {
                return '';
            }
            return '<style>'."\n".file_get_contents($p)."\n".'</style>';
        },
        $html
    );
    $html = preg_replace_callback(
        '#<script type="module" src="[^"]*/build/(assets/[^"]+\.js)"[^>]*></script>#',
        function ($m) {
            $p = public_path('build/'.$m[1]);
            if (! is_file($p)) {
                return '';
            }
            return '<script type="module">'."\n".file_get_contents($p)."\n".'</script>';
        },
        $html
    );
    $html = preg_replace('/<link rel="manifest"[^>]*>/', '', $html);
    file_put_contents(sprintf('%s/%s.html', $outDir, $name), $html);
    echo "rendered: {$name}\n";
}

function login(Client $client, string $email, string $password): bool
{
    $res = $client->get('/login');
    $html = (string) $res->getBody();
    if (! preg_match('/name="_token" value="([^"]+)"/', $html, $m)) {
        echo "no csrf token\n";
        return false;
    }
    $client->post('/login', [
        'form_params' => [
            '_token' => $m[1],
            'email' => $email,
            'password' => $password,
        ],
    ]);

    return true;
}

function renderRoutes(Client $client, string $tag, array $routes): void
{
    foreach ($routes as $name => $path) {
        try {
            $res = $client->get($path);
            if ($res->getStatusCode() === 200) {
                save($tag.'-'.$name, (string) $res->getBody());
            } else {
                echo "SKIP {$path} => {$res->getStatusCode()}\n";
            }
        } catch (\Throwable $e) {
            echo "ERR  {$path} => ".$e->getMessage()."\n";
        }
    }
}

$tenant = new Client([
    'base_uri' => 'http://127.0.0.1:8123',
    'cookies' => new CookieJar,
    'http_errors' => false,
]);

if (! login($tenant, 'lucass_br@hotmail.com', 'Teste@12345')) {
    echo "tenant login FAILED\n";
    exit(1);
}
echo "tenant login OK\n";

renderRoutes($tenant, 'app', [
    'dashboard' => '/app',
    'agenda' => '/app/agenda',
    'clientes' => '/app/clientes',
    'cliente-show' => '/app/clientes/1',
    'cliente-edit' => '/app/clientes/1/editar',
    'cliente-novo' => '/app/clientes/novo',
    'servicos' => '/app/servicos',
    'servico-show' => '/app/servicos/1',
    'servico-edit' => '/app/servicos/1/editar',
    'servico-novo' => '/app/servicos/novo',
    'barbeiros' => '/app/barbeiros',
    'barbeiro-show' => '/app/barbeiros/1',
    'barbeiro-edit' => '/app/barbeiros/1/editar',
    'barbeiro-novo' => '/app/barbeiros/novo',
    'equipe' => '/app/equipe',
    'equipe-show' => '/app/equipe/1',
    'equipe-novo' => '/app/equipe/novo',
    'atendimento' => '/app/atendimentos/1',
    'caixa' => '/app/caixa',
    'vendas' => '/app/vendas',
    'comissoes' => '/app/comissoes',
    'settings-horarios' => '/app/configuracoes/horarios',
    'settings-barbearia' => '/app/configuracoes/minha-barbearia',
    'settings-pagamentos' => '/app/configuracoes/pagamentos',
    'settings-permissoes' => '/app/configuracoes/permissoes',
    'notificacoes' => '/app/notificacoes/agendamentos',
]);

$guest = new Client([
    'base_uri' => 'http://127.0.0.1:8123',
    'cookies' => new CookieJar,
    'http_errors' => false,
]);
renderRoutes($guest, 'guest', [
    'login' => '/login',
    'forgot' => '/forgot-password',
    'booking' => '/agendar/teste',
]);

$super = new Client([
    'base_uri' => 'http://127.0.0.1:8123',
    'cookies' => new CookieJar,
    'http_errors' => false,
]);
if (login($super, config('superadmin.email'), config('superadmin.password'))) {
    echo "superadmin login OK\n";
    renderRoutes($super, 'super', [
        'barbearias' => '/admin/barbearias',
        'barbearia-nova' => '/admin/barbearias/nova',
    ]);
} else {
    echo "superadmin login FAILED\n";
}

echo "DONE\n";