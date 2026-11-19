<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Administration') - {{ \App\Models\PlatformSetting::siteSettings()['name'] ?? 'MboaCar' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-light min-vh-100 d-flex flex-column">
    @auth('admin')
    @php
        $currentAdmin = auth('admin')->user();
        $adminAvatar = $currentAdmin?->avatarUrl();
    @endphp
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top shadow-sm border-bottom border-secondary border-opacity-25" style="background-color: #0f172a; z-index: 1030;">
        <div class="container-fluid px-3 px-lg-4">
            <div class="d-flex align-items-center gap-2">
                <button
                    class="btn btn-outline-light btn-sm d-lg-none"
                    type="button"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#adminMobileSidebar"
                    aria-label="Menu Admin"
                >
                    <i class="bi bi-list fs-5"></i>
                </button>
                <a class="navbar-brand fw-bold d-flex align-items-center gap-2 mb-0" href="{{ route('admin.dashboard') }}">
                    <img src="{{ \App\Models\PlatformSetting::siteSettings()['logo_url'] ?? asset('assets/images/logo.png') }}" alt="MboaCar" class="bg-white rounded-2 px-2 py-1" style="height: 34px; width: auto; object-fit: contain;">
                    <span class="badge bg-warning text-dark small fw-semibold" style="font-size: 0.7rem;">Super-Admin</span>
                </a>
            </div>

            <div class="d-flex align-items-center gap-2 gap-md-3 ms-auto text-white">
                <a href="{{ url('/') }}" target="_blank" class="btn btn-outline-light btn-sm d-none d-sm-inline-flex align-items-center gap-1">
                    <i class="bi bi-globe"></i><span>Site</span>
                </a>
                <a href="{{ url('/marketplace') }}" target="_blank" class="btn btn-outline-warning btn-sm d-none d-md-inline-flex align-items-center gap-1">
                    <i class="bi bi-shop"></i><span>Marketplace</span>
                </a>

                {{-- Menu déroulant Profil Admin (Photo + Nom à l'extrême droite) --}}
                <div class="dropdown">
                    <button
                        type="button"
                        class="btn btn-dark border border-secondary border-opacity-50 rounded-pill d-inline-flex align-items-center gap-2 py-1 ps-1 pe-3 dropdown-toggle"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                    >
                        @if($adminAvatar)
                            <img src="{{ $adminAvatar }}" alt="{{ $currentAdmin->name }}" class="rounded-circle border border-warning" style="width: 30px; height: 30px; object-fit: cover;">
                        @else
                            <span class="rounded-circle bg-warning text-dark fw-bold d-inline-flex align-items-center justify-content-center" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                {{ strtoupper(substr($currentAdmin->name ?? 'A', 0, 1)) }}
                            </span>
                        @endif
                        <span class="fw-medium small text-white">{{ $currentAdmin->name }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 mt-2 p-1" style="min-width: 210px; z-index: 1040;">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-bold small text-dark">{{ $currentAdmin->name }}</div>
                            <div class="text-muted" style="font-size: 0.75rem;">{{ $currentAdmin->email }}</div>
                        </li>
                        <li>
                            <a class="dropdown-item rounded-2 py-2 small d-flex align-items-center gap-2" href="{{ route('admin.profile') }}">
                                <i class="bi bi-person-gear text-primary"></i>
                                <span>Modifier mon profil</span>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li>
                            <button
                                type="button"
                                class="dropdown-item rounded-2 py-2 small text-danger d-flex align-items-center gap-2"
                                data-bs-toggle="modal"
                                data-bs-target="#modalAdminConfirmLogout"
                            >
                                <i class="bi bi-box-arrow-right"></i>
                                <span>Déconnexion</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    {{-- Static Modal de confirmation de déconnexion --}}
    <div
        id="modalAdminConfirmLogout"
        class="modal fade"
        data-bs-backdrop="static"
        data-bs-keyboard="false"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-dark text-white border-0">
                    <h5 class="modal-title fw-bold fs-6">
                        <i class="bi bi-box-arrow-right text-warning me-2"></i>Confirmation
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body p-4 text-center small text-muted">
                    Voulez-vous vraiment vous déconnecter de l'espace Super-Admin ?
                </div>
                <div class="modal-footer border-0 bg-light justify-content-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Annuler</button>
                    <form method="POST" action="{{ route('admin.logout') }}" class="mb-0">
                        @csrf
                        <button type="submit" class="btn btn-danger btn-sm px-3 fw-semibold">
                            Se déconnecter
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endauth

    <main class="flex-grow-1 d-flex flex-column">
        @yield('content')
    </main>

    @livewireScripts
    <script>
        function toggleAdminPwd(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
        }

        function initAdminDatepickers() {
            if (!window.$ || !window.$.fn || !window.$.fn.datepicker) return;
            window.$('.admin-datepicker').each(function () {
                const $el = window.$(this);
                if ($el.data('datepicker')) return;
                $el.datepicker({
                    dateFormat: 'yy-mm-dd',
                    changeMonth: true,
                    changeYear: true,
                    yearRange: '1940:2015',
                    onSelect: function (dateText) {
                        this.value = dateText;
                        this.dispatchEvent(new Event('change', { bubbles: true }));
                    },
                });
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            let tries = 0;
            const timer = setInterval(() => {
                initAdminDatepickers();
                if ((window.$ && window.$.fn && window.$.fn.datepicker) || ++tries > 20) {
                    clearInterval(timer);
                }
            }, 150);
        });
        document.addEventListener('livewire:navigated', initAdminDatepickers);

        function adminWysiwygSync(editorId, field) {
            const el = document.getElementById(editorId);
            if (!el) return;
            const lw = window.Livewire?.find(el.closest('[wire\\:id]')?.getAttribute('wire:id'));
            if (lw) {
                lw.set(field, el.innerHTML);
            }
        }

        function adminWysiwygExec(editorId, field, cmd, val = null) {
            const el = document.getElementById(editorId);
            if (!el) return;
            el.focus();
            document.execCommand(cmd, false, val);
            adminWysiwygSync(editorId, field);
        }

        function adminWysiwygLink(editorId, field) {
            const url = prompt('Entrez l\'URL du lien (ex: https://...):', 'https://');
            if (url) {
                adminWysiwygExec(editorId, field, 'createLink', url);
            }
        }

        function adminWysiwygTable(editorId, field) {
            const rows = Math.max(1, Math.min(15, parseInt(prompt('Nombre de lignes :', '3') || '3', 10)));
            const cols = Math.max(1, Math.min(10, parseInt(prompt('Nombre de colonnes :', '3') || '3', 10)));
            let html = '<table class="table table-bordered table-sm my-3"><thead><tr>';
            for (let c = 1; c <= cols; c++) html += `<th>Colonne ${c}</th>`;
            html += '</tr></thead><tbody>';
            for (let r = 1; r < rows; r++) {
                html += '<tr>';
                for (let c = 1; c <= cols; c++) html += '<td>Cellule</td>';
                html += '</tr>';
            }
            html += '</tbody></table><p><br></p>';
            adminWysiwygExec(editorId, field, 'insertHTML', html);
        }
    </script>
</body>
</html>
