<x-app-layout title="Minha Barbearia">
    <div class="mb-4">
        <h1 class="h3 mb-1">Configurações da barbearia</h1>
        <p class="text-secondary mb-0">Atualize os dados e a identidade visual exibidos no painel e na página de agendamento.</p>
    </div>
    @include('app.settings._navigation')

    <div class="row g-4">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('app.settings.barbershop.update') }}"
                  enctype="multipart/form-data" class="card">
                @csrf
                @method('PUT')
                <div class="card-header"><h2 class="h5 mb-0">Dados e identidade visual</h2></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nome da barbearia</label>
                        <input id="name" name="name" type="text" maxlength="255"
                               value="{{ old('name', $currentBarbershop->name) }}"
                               class="form-control @error('name') is-invalid @enderror" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="document" class="form-label">CNPJ ou documento <span class="text-secondary">(opcional)</span></label>
                            <input id="document" name="document" type="text" maxlength="32"
                                   value="{{ old('document', $currentBarbershop->document) }}"
                                   class="form-control @error('document') is-invalid @enderror">
                            @error('document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label">Telefone</label>
                            <input id="phone" name="phone" type="tel" maxlength="32"
                                   value="{{ old('phone', $currentBarbershop->phone) }}"
                                   class="form-control @error('phone') is-invalid @enderror">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="email" class="form-label">E-mail de contato</label>
                            <input id="email" name="email" type="email" maxlength="255"
                                   value="{{ old('email', $currentBarbershop->email) }}"
                                   class="form-control @error('email') is-invalid @enderror">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <hr class="my-4">
                    <div class="mb-3">
                        <label for="logo" class="form-label">Logo</label>
                        <input id="logo" name="logo" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                               class="form-control @error('logo') is-invalid @enderror">
                        <div class="form-text">JPG, PNG ou WebP; tamanho máximo de 2 MB.</div>
                        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @if (filled($currentBarbershop->logo_path))
                        <div class="form-check">
                            <input id="remove_logo" name="remove_logo" type="checkbox" value="1" class="form-check-input">
                            <label for="remove_logo" class="form-check-label">Remover logo e usar o ícone padrão</label>
                        </div>
                    @endif
                </div>
                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-floppy-disk me-1" aria-hidden="true"></i>
                        Salvar alterações
                    </button>
                </div>
            </form>
        </div>
        <div class="col-xl-4">
            <section class="card h-100">
                <div class="card-header"><h2 class="h5 mb-0">Prévia da marca</h2></div>
                <div class="card-body d-flex flex-column align-items-center justify-content-center text-center">
                    @if (filled($currentBarbershop->logo_path))
                        <img src="{{ asset('storage/'.$currentBarbershop->logo_path) }}" alt="Logo da barbearia"
                             class="img-fluid rounded mb-3" style="max-width: 180px; max-height: 180px; object-fit: contain;">
                    @else
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-body-secondary mb-3"
                             style="width: 96px; height: 96px;">
                            <i class="fas fa-scissors fs-2 text-secondary" aria-hidden="true"></i>
                        </div>
                        <p class="small text-secondary">O ícone padrão será usado enquanto não houver logo.</p>
                    @endif
                    <div class="fs-5 fw-semibold">{{ filled($currentBarbershop->name) ? $currentBarbershop->name : config('app.name', 'Barber SaaS') }}</div>
                    <div class="small text-secondary">{{ $currentBarbershop->email ?: 'E-mail não informado' }}</div>
                    <div class="small text-secondary">{{ $currentBarbershop->phone ?: 'Telefone não informado' }}</div>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
