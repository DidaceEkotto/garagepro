<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') — @yield('title') | {{ config('app.name', 'GaragePro') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: radial-gradient(circle at top right, #1e3a8a 0%, #0f172a 55%, #020617 100%);
            font-family: "Poppins", system-ui, -apple-system, sans-serif;
        }
        .error-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.55);
        }
        .error-code {
            font-size: clamp(3.5rem, 9vw, 5.5rem);
            font-weight: 800;
            letter-spacing: -0.04em;
            background: linear-gradient(135deg, #0f172a 0%, #2563eb 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3 p-md-4">
    <div class="container" style="max-width: 720px;">
        <div class="error-card p-4 p-md-5 text-center">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary mb-3" style="width: 72px; height: 72px;">
                <i class="bi @yield('icon', 'bi-exclamation-triangle') fs-1"></i>
            </div>
            <div class="error-code lh-1 mb-2">@yield('code')</div>
            <h1 class="h3 fw-bold text-dark mb-2">@yield('title')</h1>
            <p class="text-muted mb-4">@yield('message')</p>

            @if(config('app.debug') && isset($exception))
                <div class="text-start bg-dark text-light rounded-4 p-3 p-md-4 mb-4 border border-secondary shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-warning text-dark fw-semibold">
                            <i class="bi bi-bug-fill me-1"></i>{{ __('errors.debug_badge') }}
                        </span>
                        <span class="small text-secondary">{{ get_class($exception) }}</span>
                    </div>
                    <div class="small text-warning fw-semibold mb-2">
                        {{ $exception->getMessage() ?: 'No detailed error message.' }}
                    </div>
                    @if(method_exists($exception, 'getFile') && $exception->getFile())
                        <div class="small text-secondary text-break">
                            <code>{{ $exception->getFile() }}:{{ $exception->getLine() }}</code>
                        </div>
                    @endif
                </div>
            @endif

            <div class="d-flex flex-wrap justify-content-center gap-2">
                <a href="{{ url('/') }}" class="btn btn-primary px-4 py-2 fw-semibold">
                    <i class="bi bi-house-door me-1"></i>{{ __('errors.home') }}
                </a>
                <a href="{{ url('/marketplace') }}" class="btn btn-outline-primary px-4 py-2 fw-semibold">
                    <i class="bi bi-shop me-1"></i>{{ __('errors.marketplace') }}
                </a>
                <button type="button" class="btn btn-outline-secondary px-4 py-2" onclick="history.back()">
                    <i class="bi bi-arrow-left me-1"></i>{{ __('errors.back') }}
                </button>
            </div>
        </div>
    </div>
</body>
</html>
