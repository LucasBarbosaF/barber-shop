{{--
    Casca do painel: sidebar + header + conteúdo + footer (AdminLTE 4).
    O HTML vive em `layouts/app.blade.php` — este componente só o expõe como
    `<x-app-layout>` para as páginas já existentes não mudarem de assinatura.
--}}
@include('layouts.app', ['assets' => $assets ?? []])
