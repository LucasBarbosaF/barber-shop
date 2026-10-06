<x-app-layout title="Nova barbearia">
    <div class="mb-4">
        <h1 class="h3 mb-0">Nova barbearia</h1>
        <p class="text-secondary mb-0">
            O cadastro cria a barbearia e o usuário responsável em uma única etapa.
            Não há registro público: todo acesso nasce aqui.
        </p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.barbershops.store') }}">
                @csrf

                <fieldset class="mb-4">
                    <legend class="h5 float-none w-auto mb-3">Barbearia</legend>

                    <div class="mb-3">
                        <label for="name" class="form-label">Nome</label>
                        <input id="name" type="text" name="name" value="{{ old('name') }}"
                               class="form-control @error('name') is-invalid @enderror" required autofocus>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="document" class="form-label">CNPJ / CPF</label>
                            <input id="document" type="text" name="document" value="{{ old('document') }}"
                                   class="form-control @error('document') is-invalid @enderror">
                            @error('document')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Telefone</label>
                            <input id="phone" type="tel" inputmode="numeric" maxlength="15"
                                   data-mask="phone" placeholder="(11) 99210-8613"
                                   name="phone" value="{{ old('phone') }}"
                                   class="form-control @error('phone') is-invalid @enderror">
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="email" class="form-label">E-mail de contato</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}"
                               class="form-control @error('email') is-invalid @enderror">
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </fieldset>

                <fieldset class="border-top pt-4 mb-0">
                    <legend class="h5 float-none w-auto mb-3">Responsável</legend>
                    <p class="text-secondary">
                        A senha provisória será gerada e exibida uma única vez após o cadastro.
                    </p>

                    <div class="mb-3">
                        <label for="owner_name" class="form-label">Nome</label>
                        <input id="owner_name" type="text" name="owner_name" value="{{ old('owner_name') }}"
                               class="form-control @error('owner_name') is-invalid @enderror" required>
                        @error('owner_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-0">
                        <label for="owner_email" class="form-label">E-mail de acesso</label>
                        <input id="owner_email" type="email" name="owner_email" value="{{ old('owner_email') }}"
                               class="form-control @error('owner_email') is-invalid @enderror" required>
                        @error('owner_email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </fieldset>

                <div class="border-top pt-4 mt-4 d-flex align-items-center gap-3">
                    <button type="submit" class="btn btn-primary">Cadastrar barbearia</button>
                    <a href="{{ route('admin.barbershops.index') }}" class="btn btn-link text-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
