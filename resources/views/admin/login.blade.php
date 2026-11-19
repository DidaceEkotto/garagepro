@extends('layouts.admin')

@section('title', 'Connexion Super-Admin')

@section('content')
<div class="row justify-content-center mt-5">
    <div class="col-md-5">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h1 class="h4 fw-bold mb-1">Back-office Plateforme</h1>
                <div class="mb-3">
                    <img src="{{ \App\Models\PlatformSetting::siteSettings()['logo_url'] ?? asset('assets/images/logo.png') }}" alt="MboaCar" style="height: 44px; width: auto; object-fit: contain;">
                </div>
                <p class="text-muted small mb-4">Accès réservé aux administrateurs MboaCar</p>

                <form method="POST" action="{{ route('admin.login.post') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="email" class="form-label">Adresse e-mail</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required autofocus>
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Mot de passe</label>
                        <div class="input-group">
                            <input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="const i=document.getElementById('password'); const icon=this.querySelector('i'); if(i.type==='password'){i.type='text';icon.className='bi bi-eye-slash';}else{i.type='password';icon.className='bi bi-eye';}">
                                <i class="bi bi-eye"></i>
                            </button>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <button type="submit" class="btn btn-dark w-100">Se connecter</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
