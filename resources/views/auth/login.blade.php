<x-guest-layout>
    <main class="auth-login-shell">
        <section class="auth-login-panel" aria-labelledby="login-heading">
            <div class="auth-login-brand">
                @if (filled($currentBarbershop?->logo_path))
                    <img src="{{ asset('storage/'.$currentBarbershop->logo_path) }}"
                         alt="Logo de {{ $currentBarbershop->name }}" class="auth-login-logo">
                @else
                    <span class="auth-login-brand-icon" aria-hidden="true">
                        <i class="fas fa-scissors"></i>
                    </span>
                @endif
                <span>{{ filled($currentBarbershop?->name) ? $currentBarbershop->name : config('app.name', 'Barber SaaS') }}</span>
            </div>

            <div class="auth-login-intro">
                <span class="auth-login-eyebrow">GESTÃO DA BARBEARIA</span>
                <h1 id="login-heading">Bom ter você por aqui.</h1>
                <p>Acesse sua conta para acompanhar a agenda, os atendimentos e a operação da sua barbearia.</p>
            </div>

            <div class="auth-login-card">
                <div class="auth-login-card-heading">
                    <h2>Entrar na sua conta</h2>
                    <p>Use seu e-mail e sua senha para continuar.</p>
                </div>

                @if (session('status'))
                    <div class="alert alert-success" role="status">
                        <i class="fas fa-circle-check me-1" aria-hidden="true"></i>
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger" role="alert" tabindex="-1" data-login-errors>
                        <strong>Não foi possível entrar.</strong>
                        <ul class="mb-0 mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <div class="input-group auth-login-input">
                            <span class="input-group-text" aria-hidden="true">
                                <i class="fas fa-envelope"></i>
                            </span>
                            <input id="email" name="email" type="email" value="{{ old('email') }}"
                                   class="form-control @error('email') is-invalid @enderror"
                                   placeholder="voce@exemplo.com" autocomplete="username"
                                   inputmode="email" autocapitalize="none" spellcheck="false"
                                   @error('email') aria-describedby="email-error" @enderror
                                   required autofocus>
                            @error('email')
                                <div id="email-error" class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <label for="password" class="form-label">Senha</label>
                            @if (Route::has('password.request'))
                                <a class="auth-login-recovery" href="{{ route('password.request') }}">Esqueceu a senha?</a>
                            @endif
                        </div>
                        <div class="input-group auth-login-input">
                            <span class="input-group-text" aria-hidden="true">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input id="password" name="password" type="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   placeholder="Digite sua senha" autocomplete="current-password"
                                   @error('password') aria-describedby="password-error" @enderror
                                   required>
                            <button class="input-group-text auth-login-password-toggle" type="button"
                                    data-toggle-password aria-label="Mostrar senha" aria-pressed="false">
                                <i class="far fa-eye" aria-hidden="true"></i>
                            </button>
                            @error('password')
                                <div id="password-error" class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                        <div class="form-check">
                            <input id="remember" type="checkbox" name="remember" value="1"
                                   class="form-check-input" @checked(old('remember'))>
                            <label for="remember" class="form-check-label">Manter conectado</label>
                        </div>
                        <span class="auth-login-private">
                            <i class="fas fa-shield-halved me-1" aria-hidden="true"></i> Acesso seguro
                        </span>
                    </div>

                    <button type="submit" class="btn btn-primary auth-login-submit">
                        Entrar <i class="fas fa-arrow-right ms-2" aria-hidden="true"></i>
                    </button>
                </form>

                <p class="auth-login-help mb-0">
                    Problemas para acessar? Entre em contato com o administrador da sua barbearia.
                </p>
            </div>

            <footer class="auth-login-footer">
                &copy; {{ now()->year }} {{ filled($currentBarbershop?->name) ? $currentBarbershop->name : config('app.name', 'Barber SaaS') }}
            </footer>
        </section>
        <aside class="auth-login-visual" aria-hidden="true">
            <div class="auth-login-visual-content">
                <span class="auth-login-visual-mark"><i class="fas fa-scissors"></i></span>
                <span class="auth-login-eyebrow">SUA BARBEARIA, EM UM SÓ LUGAR</span>
                <p>Mais organização para sua equipe. Mais tempo para cuidar de cada cliente.</p>
                <div class="auth-login-visual-line"></div>
                <span class="auth-login-visual-caption">Agenda · Atendimento · Gestão</span>
            </div>
        </aside>
    </main>
</x-guest-layout>
