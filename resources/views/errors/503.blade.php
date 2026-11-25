<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>503 — {{ __('errors.503_title') }} | {{ config('app.name', 'MboaCar') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: radial-gradient(circle at top right, #1e3a8a 0%, #0f172a 55%, #020617 100%);
            font-family: "Poppins", system-ui, -apple-system, sans-serif;
            overflow-x: hidden;
        }
        .maint-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.55);
        }
        @keyframes spinSlow {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @keyframes spinReverse {
            from { transform: rotate(360deg); }
            to { transform: rotate(0deg); }
        }
        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.35); }
            50% { box-shadow: 0 0 0 18px rgba(37, 99, 235, 0); }
        }
        .gear-spin {
            display: inline-block;
            animation: spinSlow 6s linear infinite;
        }
        .gear-spin-reverse {
            display: inline-block;
            animation: spinReverse 4s linear infinite;
        }
        .pulse-ring {
            animation: pulseGlow 2.4s ease-in-out infinite;
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3 p-md-4">
    <div class="container" style="max-width: 680px;">
        <div class="maint-card p-4 p-md-5 text-center">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary mb-4 pulse-ring" style="width: 96px; height: 96px;">
                <i class="bi bi-gear-fill fs-1 gear-spin"></i>
                <i class="bi bi-gear-fill fs-4 text-warning gear-spin-reverse" style="margin-left: -10px; margin-top: 26px;"></i>
            </div>

            <div class="badge bg-warning text-dark rounded-pill px-3 py-1 mb-3 fw-semibold">
                <i class="bi bi-tools me-1"></i>Maintenance en cours
            </div>

            <h1 class="h2 fw-bold text-dark mb-3">{{ __('errors.503_title') }}</h1>
            <p class="text-muted mb-4" style="line-height: 1.7;">
                {{ !empty($maintenanceMessage) ? $maintenanceMessage : __('errors.503_message') }}
            </p>

            <div class="progress mx-auto mb-3" style="max-width: 320px; height: 6px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 75%;"></div>
            </div>
            <div class="small text-muted">Merci de votre patience. Nous revenons très vite !</div>
        </div>
    </div>
</body>
</html>
