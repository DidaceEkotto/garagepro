<div class="d-flex flex-grow-1">
    @php
        $currentAdminUser = auth('admin')->user();
        $navGroups = [
            [
                'title' => 'Pilotage',
                'items' => [
                    ['key' => 'overview', 'route' => 'admin.dashboard', 'label' => "Vue d'ensemble", 'icon' => 'bi-speedometer2'],
                    ['key' => 'tenants', 'route' => 'admin.tenants', 'label' => 'Garages (Tenants)', 'icon' => 'bi-buildings'],
                    ['key' => 'messages', 'route' => 'admin.messages', 'label' => 'Messagerie', 'icon' => 'bi-chat-dots', 'badge' => $unreadAdminMessages],
                ],
            ],
            [
                'title' => 'Croissance & Marketplace',
                'items' => [
                    ['key' => 'affiliation', 'route' => 'admin.affiliation', 'label' => 'Affiliation', 'icon' => 'bi-ticket-perforated'],
                    ['key' => 'categories', 'route' => 'admin.categories', 'label' => 'Catégories', 'icon' => 'bi-tags'],
                    ['key' => 'banners', 'route' => 'admin.banners', 'label' => 'Bannières Publicitaires', 'icon' => 'bi-megaphone'],
                    ['key' => 'testimonials', 'route' => 'admin.testimonials', 'label' => 'Témoignages', 'icon' => 'bi-star'],
                    ['key' => 'countries', 'route' => 'admin.countries', 'label' => 'Pays & Indicatifs', 'icon' => 'bi-globe-europe-africa'],
                    ['key' => 'terms', 'route' => 'admin.terms', 'label' => "Conditions d'utilisation", 'icon' => 'bi-file-earmark-text'],
                    ['key' => 'privacy', 'route' => 'admin.privacy', 'label' => 'Politique de confidentialité', 'icon' => 'bi-shield-check'],
                ],
            ],
            [
                'title' => 'Système & Sécurité',
                'items' => [
                    ['key' => 'security', 'route' => 'admin.security', 'label' => 'Sécurité & Blocage IP', 'icon' => 'bi-shield-lock'],
                    ['key' => 'admins', 'route' => 'admin.admins', 'label' => 'Administrateurs', 'icon' => 'bi-people-fill'],
                    ['key' => 'settings', 'route' => 'admin.settings', 'label' => 'Paramètres & SEO', 'icon' => 'bi-gear'],
                    ['key' => 'profile', 'route' => 'admin.profile', 'label' => 'Mon Profil', 'icon' => 'bi-person-gear'],
                ],
            ],
        ];
        $navGroups = collect($navGroups)->map(function ($group) use ($currentAdminUser) {
            $group['items'] = collect($group['items'])
                ->filter(fn ($it) => ! $currentAdminUser || $currentAdminUser->canAccessSection($it['key']))
                ->values()
                ->all();

            return $group;
        })->filter(fn ($g) => count($g['items']) > 0)->all();
    @endphp

    {{-- Sidebar verticale (Desktop) --}}
    <aside class="d-none d-lg-flex flex-column bg-white border-end p-3 flex-shrink-0" style="width: 265px;">
        @foreach($navGroups as $group)
            <div class="mb-3">
                <div class="text-uppercase text-muted fw-bold px-2 mb-1" style="font-size: 0.7rem; letter-spacing: 0.04em;">
                    {{ $group['title'] }}
                </div>
                <ul class="nav nav-pills flex-column gap-1">
                    @foreach($group['items'] as $item)
                        <li class="nav-item">
                            <a
                                href="{{ route($item['route']) }}"
                                class="nav-link sidebar-link w-100 text-start d-flex align-items-center justify-content-between py-2 px-3 small {{ $activeSection === $item['key'] ? 'active' : '' }}"
                            >
                                <span class="d-flex align-items-center gap-2">
                                    <i class="bi {{ $item['icon'] }}"></i>
                                    <span>{{ $item['label'] }}</span>
                                </span>
                                @if(!empty($item['badge']) && $item['badge'] > 0)
                                    <span class="badge bg-danger rounded-pill">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </aside>

    {{-- Sidebar mobile (Offcanvas) --}}
    <div id="adminMobileSidebar" class="offcanvas offcanvas-start" tabindex="-1">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title fw-bold">
                <i class="bi bi-shield-check text-primary me-2"></i>Menu Admin
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            @foreach($navGroups as $group)
                <div class="mb-3">
                    <div class="text-uppercase text-muted fw-bold px-2 mb-1" style="font-size: 0.7rem;">
                        {{ $group['title'] }}
                    </div>
                    <ul class="nav nav-pills flex-column gap-1">
                        @foreach($group['items'] as $item)
                            <li class="nav-item">
                                <a
                                    href="{{ route($item['route']) }}"
                                    class="nav-link sidebar-link w-100 text-start d-flex align-items-center justify-content-between py-2 px-3 small {{ $activeSection === $item['key'] ? 'active' : '' }}"
                                >
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi {{ $item['icon'] }}"></i>
                                        <span>{{ $item['label'] }}</span>
                                    </span>
                                    @if(!empty($item['badge']) && $item['badge'] > 0)
                                        <span class="badge bg-danger rounded-pill">{{ $item['badge'] }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Contenu principal --}}
    <div class="flex-grow-1 p-3 p-md-4 overflow-auto">
        @if($feedbackMessage || session('admin_success'))
            <div class="alert alert-success border-0 shadow-sm alert-dismissible fade show mb-4">
                <i class="bi bi-check-circle-fill me-2"></i>{{ $feedbackMessage ?: session('admin_success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger border-0 shadow-sm mb-4 small">
                <ul class="mb-0 ps-3">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- 1. VUE D'ENSEMBLE --}}
        @if($activeSection === 'overview')
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h4 fw-bold mb-0">Vue d'ensemble Plateforme</h1>
                    <p class="text-muted small mb-0">Indicateurs clés, paiements récents et activité globale</p>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="card border-0 shadow-sm rounded-4"><div class="card-body">
                        <div class="small text-muted">Tenants Actifs</div>
                        <div class="h3 fw-bold text-success mb-0">{{ $kpis['activeTenants'] }}</div>
                    </div></div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card border-0 shadow-sm rounded-4"><div class="card-body">
                        <div class="small text-muted">Essais en cours</div>
                        <div class="h3 fw-bold text-primary mb-0">{{ $kpis['trialTenants'] }}</div>
                    </div></div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card border-0 shadow-sm rounded-4"><div class="card-body">
                        <div class="small text-muted">MRR Estimé</div>
                        <div class="h3 fw-bold mb-0">{{ number_format($kpis['mrr'], 0, ',', ' ') }} FCFA</div>
                    </div></div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card border-0 shadow-sm rounded-4"><div class="card-body">
                        <div class="small text-muted">Impayés (Past Due)</div>
                        <div class="h3 fw-bold text-danger mb-0">{{ $kpis['pastDueTenants'] }}</div>
                    </div></div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                        <div class="card-header bg-white fw-bold py-3">Paiements récents</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>Référence</th><th>Montant</th><th>Statut</th><th>Date</th></tr>
                                </thead>
                                <tbody>
                                    @forelse($recentPayments as $p)
                                    <tr>
                                        <td><code>{{ $p->reference_code }}</code></td>
                                        <td class="fw-semibold">{{ number_format($p->amount, 0, ',', ' ') }} {{ $p->currency }}</td>
                                        <td><span class="badge {{ $p->status === 'success' ? 'bg-success' : 'bg-warning text-dark' }}">{{ $p->status }}</span></td>
                                        <td class="small text-muted">{{ $p->created_at?->format('d/m H:i') }}</td>
                                    </tr>
                                    @empty
                                    <tr><td colspan="4" class="text-center text-muted py-3">Aucun paiement.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                        <div class="card-header bg-white fw-bold py-3">Derniers événements d'audit</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>Action</th><th>IP</th><th>Date</th></tr>
                                </thead>
                                <tbody>
                                    @forelse($auditLogs as $log)
                                    <tr>
                                        <td><code>{{ $log->action }}</code></td>
                                        <td class="small text-muted">{{ $log->ip_address }}</td>
                                        <td class="small text-muted">{{ $log->created_at?->format('d/m H:i') }}</td>
                                    </tr>
                                    @empty
                                    <tr><td colspan="3" class="text-center text-muted py-3">Aucun log.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- 2. GARAGES (TENANTS) --}}
        @if($activeSection === 'tenants')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Gestion des Garages (Tenants)</h1>
                <p class="text-muted small mb-0">Administration des comptes, prolongations d'essai, suspensions et accès support</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Garage</th>
                                <th>Sous-domaine</th>
                                <th>Email</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tenants as $t)
                            <tr>
                                <td class="fw-semibold">{{ $t->name }}</td>
                                <td><code>{{ $t->slug }}</code></td>
                                <td class="small text-muted">{{ $t->email }}</td>
                                <td><span class="badge bg-info text-dark">{{ $t->status }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button wire:click="extendTrial({{ $t->id }}, 7)" class="btn btn-outline-primary btn-sm">+7j essai</button>
                                    @if($t->status === 'suspended')
                                        <button wire:click="reactivateTenant({{ $t->id }})" class="btn btn-outline-success btn-sm">Réactiver</button>
                                    @else
                                        <button wire:click="suspendTenant({{ $t->id }})" class="btn btn-outline-danger btn-sm">Suspendre</button>
                                    @endif
                                    <button wire:click="impersonateTenant({{ $t->id }})" class="btn btn-dark btn-sm">Support</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- 3. MESSAGERIE --}}
        @if($activeSection === 'messages')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Messagerie Plateforme ({{ $unreadAdminMessages }} non lu(s))</h1>
                <p class="text-muted small mb-0">Communiquez directement avec les garages inscrits</p>
            </div>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 p-4">
                        <h2 class="h6 fw-bold mb-3">Envoyer un message à un garage</h2>
                        <form wire:submit="sendAdminMessage" class="vstack gap-3">
                            <div>
                                <label class="form-label small fw-semibold mb-1">Garage destinataire</label>
                                <select wire:model="adminMsgTenantId" class="form-select form-select-sm">
                                    <option value="">Choisir un garage...</option>
                                    @foreach($tenants as $t)
                                        <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->slug }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label small fw-semibold mb-1">Objet</label>
                                <input wire:model="adminMsgSubject" type="text" class="form-control form-control-sm" placeholder="Sujet du message">
                            </div>
                            <div>
                                <label class="form-label small fw-semibold mb-1">Message</label>
                                <textarea wire:model="adminMsgBody" rows="4" class="form-control form-control-sm" placeholder="Votre message..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm fw-semibold">Envoyer le message</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                        <div class="card-header bg-white fw-bold py-3">Derniers échanges</div>
                        <div class="list-group list-group-flush">
                            @forelse($adminMessages as $msg)
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-bold small">
                                            {{ $msg->sender_name }}
                                            @if($msg->tenant)
                                                <span class="badge bg-light text-dark border ms-1">{{ $msg->tenant->name }}</span>
                                            @endif
                                        </span>
                                        <span class="small text-muted">{{ $msg->created_at?->format('d/m/Y H:i') }}</span>
                                    </div>
                                    <div class="small fw-semibold text-primary mb-1">{{ $msg->subject }}</div>
                                    <div class="small text-muted mb-2">{{ $msg->body }}</div>

                                    @if($replyingMsgId === $msg->id)
                                        <form wire:submit="submitAdminReply" class="d-flex gap-2">
                                            <input wire:model="adminReplyBody" type="text" class="form-control form-control-sm" placeholder="Votre réponse...">
                                            <button type="submit" class="btn btn-primary btn-sm">Envoyer</button>
                                            <button type="button" wire:click="$set('replyingMsgId', null)" class="btn btn-outline-secondary btn-sm">Annuler</button>
                                        </form>
                                    @else
                                        <button type="button" wire:click="startAdminReply({{ $msg->id }})" class="btn btn-outline-primary btn-sm py-0 px-2">
                                            Répondre
                                        </button>
                                    @endif
                                </div>
                            @empty
                                <div class="p-4 text-center text-muted small">Aucun message pour le moment.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- 4. AFFILIATION --}}
        @if($activeSection === 'affiliation')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Gestion des Liens d'Affiliation ({{ $affiliateLinks->count() }})</h1>
                <p class="text-muted small mb-0">Contrôlez les codes promo, bloquez les abus et consultez les profils inscrits</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white fw-bold d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                    <span>Liens d'affiliation actifs et bloqués</span>
                    <div style="min-width: 280px;">
                        <input wire:model="affiliateBlockReason" type="text" class="form-control form-control-sm" placeholder="Motif optionnel avant blocage...">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Garage / Propriétaire</th>
                                <th>Inscrits</th>
                                <th>Solde</th>
                                <th>Total gagné</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($affiliateLinks as $aff)
                            <tr>
                                <td><code>{{ $aff->code }}</code></td>
                                <td>
                                    <div class="fw-semibold">{{ $aff->tenant?->name }}</div>
                                    <div class="small text-muted">{{ $aff->user?->email }}</div>
                                </td>
                                <td><span class="badge bg-primary">{{ $aff->referrals_count }}</span></td>
                                <td class="fw-semibold text-success">{{ number_format($aff->balance, 0, ',', ' ') }} FCFA</td>
                                <td>{{ number_format($aff->total_earned, 0, ',', ' ') }} FCFA</td>
                                <td>
                                    <span class="badge {{ $aff->is_blocked ? 'bg-danger' : 'bg-success' }}">
                                        {{ $aff->is_blocked ? 'Bloqué' : 'Actif' }}
                                    </span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button wire:click="inspectAffiliateReferrals({{ $aff->id }})" class="btn btn-outline-primary btn-sm">
                                        {{ $selectedAffiliateLinkId === $aff->id ? 'Masquer inscrits' : 'Voir inscrits & profils' }}
                                    </button>
                                    <button wire:click="toggleAffiliateBlock({{ $aff->id }})" class="btn btn-sm {{ $aff->is_blocked ? 'btn-outline-success' : 'btn-outline-danger' }}">
                                        {{ $aff->is_blocked ? 'Débloquer' : 'Bloquer' }}
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">Aucun lien d'affiliation créé.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($selectedAffiliateLink)
                    <div class="p-3 bg-light border-top">
                        <div class="fw-bold small mb-2">
                            Profils inscrits via le code <code>{{ $selectedAffiliateLink->code }}</code> ({{ $selectedAffiliateLink->tenant?->name }})
                        </div>
                        @if($selectedAffiliateLink->referrals->isEmpty())
                            <div class="small text-muted">Aucune inscription enregistrée pour ce lien.</div>
                        @else
                            <div class="table-responsive bg-white rounded border">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Garage</th>
                                            <th>Gérant / Contact</th>
                                            <th>Ville / Pays</th>
                                            <th>Statut</th>
                                            <th>IP</th>
                                            <th>Inscrit le</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($selectedAffiliateLink->referrals as $ref)
                                        <tr>
                                            <td class="fw-semibold">
                                                {{ $ref->referredTenant?->name }}
                                                <div class="small text-muted">Slug : {{ $ref->referredTenant?->slug }}</div>
                                            </td>
                                            <td class="small">
                                                <div>{{ $ref->referredUser?->name }}</div>
                                                <div class="text-muted">{{ $ref->referredUser?->email }} • {{ $ref->referredUser?->phone ?: ($ref->referredTenant?->phone ?: '-') }}</div>
                                            </td>
                                            <td class="small">{{ $ref->referredTenant?->city ?: '-' }} ({{ $ref->referredTenant?->country ?: '-' }})</td>
                                            <td><span class="badge bg-info text-dark">{{ $ref->referredTenant?->status }}</span></td>
                                            <td class="small"><code>{{ $ref->ip_address }}</code></td>
                                            <td class="small text-muted">{{ $ref->created_at?->format('d/m/Y H:i') }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- 5. CATÉGORIES --}}
        @if($activeSection === 'categories')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Catégories &amp; Sous-catégories Marketplace</h1>
                <p class="text-muted small mb-0">Gérez les catégories globales de pièces automobiles</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <form wire:submit="addMarketplaceCategory" class="d-flex gap-2">
                            <input wire:model="mpCatName" type="text" class="form-control form-control-sm" placeholder="Nouvelle catégorie (ex: Freinage)">
                            <button type="submit" class="btn btn-primary btn-sm text-nowrap">Ajouter catégorie</button>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <form wire:submit="addMarketplaceSubCategory" class="d-flex gap-2">
                            <select wire:model="mpSubParentId" class="form-select form-select-sm">
                                <option value="">Catégorie parente...</option>
                                @foreach($mpCategories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            <input wire:model="mpSubName" type="text" class="form-control form-control-sm" placeholder="Sous-catégorie">
                            <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap">Ajouter</button>
                        </form>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach($mpCategories as $cat)
                        <div class="badge bg-light text-dark border p-2 d-inline-flex align-items-center gap-2">
                            <span class="fw-bold">{{ $cat->name }}</span>
                            <span class="text-muted">({{ $cat->subCategories->count() }})</span>
                            <button type="button" wire:click="deleteMarketplaceCategory({{ $cat->id }})" class="btn-close" style="font-size: 0.6rem;"></button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 6. BANNIÈRES PUBLICITAIRES --}}
        @if($activeSection === 'banners')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Bannières Publicitaires Marketplace</h1>
                <p class="text-muted small mb-0">Créez et personnalisez les bannières promotionnelles du Marketplace</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4">
                <h2 class="h6 fw-bold mb-3">Bannières publicitaires Marketplace</h2>
                <form wire:submit="saveBanner" class="row g-2 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Titre *</label>
                        <input wire:model="bannerTitle" type="text" class="form-control form-control-sm" placeholder="Titre de la bannière">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Sous-titre</label>
                        <input wire:model="bannerSubtitle" type="text" class="form-control form-control-sm" placeholder="Description courte...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Emplacement</label>
                        <select wire:model="bannerPlacement" class="form-select form-select-sm">
                            <option value="top">Haut de page (Sous le header)</option>
                            <option value="row_1">Après ligne 1 (Plus vendus)</option>
                            <option value="row_2">Après ligne 2 (Nouveaux)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Dégradé début</label>
                        <input wire:model="bannerGradientFrom" type="color" class="form-control form-control-color form-control-sm w-100">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Dégradé fin</label>
                        <input wire:model="bannerGradientTo" type="color" class="form-control form-control-color form-control-sm w-100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Texte bouton</label>
                        <input wire:model="bannerButtonText" type="text" class="form-control form-control-sm" placeholder="Découvrir">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Lien bouton (URL)</label>
                        <input wire:model="bannerButtonUrl" type="text" class="form-control form-control-sm" placeholder="/marketplace/bons-plans ou https://...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Image fond</label>
                        <input wire:model="bannerImage" type="file" accept="image/*" class="form-control form-control-sm">
                    </div>
                    <div class="col-12 d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm px-4">
                            {{ $editingBannerId ? 'Mettre à jour la bannière' : 'Ajouter la bannière' }}
                        </button>
                        @if($editingBannerId)
                            <button type="button" wire:click="$set('editingBannerId', null)" class="btn btn-outline-secondary btn-sm">Annuler</button>
                        @endif
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr><th>Titre</th><th>Emplacement</th><th>Bouton / Lien</th><th>Statut</th><th class="text-end">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach($mpBanners as $b)
                            <tr>
                                <td class="fw-semibold">{{ $b->title }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $b->placement === 'top' ? 'Haut de page' : ($b->placement === 'row_1' ? 'Après Ligne 1' : 'Après Ligne 2') }}</span></td>
                                <td class="small">
                                    @if($b->button_url)
                                        <span class="badge bg-info text-dark">{{ $b->button_text ?: 'Bouton' }}</span>
                                        <code class="ms-1">{{ $b->button_url }}</code>
                                    @else
                                        <span class="text-muted">Aucun bouton</span>
                                    @endif
                                </td>
                                <td><span class="badge {{ $b->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $b->is_active ? 'Actif' : 'Masqué' }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button wire:click="editBanner({{ $b->id }})" class="btn btn-outline-primary btn-sm">Modifier</button>
                                    <button wire:click="toggleBanner({{ $b->id }})" class="btn btn-outline-secondary btn-sm">{{ $b->is_active ? 'Masquer' : 'Activer' }}</button>
                                    <button wire:click="deleteBanner({{ $b->id }})" class="btn btn-outline-danger btn-sm">Supprimer</button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- 7. TÉMOIGNAGES --}}
        @if($activeSection === 'testimonials')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Témoignages Clients</h1>
                <p class="text-muted small mb-0">Gérez les avis clients affichés sur la page d'accueil</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4">
                <form wire:submit="addTestimonial" class="row g-2 mb-4">
                    <div class="col-md-3"><input wire:model="testiAuthor" type="text" class="form-control form-control-sm" placeholder="Auteur"></div>
                    <div class="col-md-3"><input wire:model="testiGarage" type="text" class="form-control form-control-sm" placeholder="Garage"></div>
                    <div class="col-md-3"><input wire:model="testiCity" type="text" class="form-control form-control-sm" placeholder="Ville"></div>
                    <div class="col-md-3"><input wire:model="testiRating" type="number" min="1" max="5" class="form-control form-control-sm" placeholder="Note /5"></div>
                    <div class="col-12"><textarea wire:model="testiQuote" rows="2" class="form-control form-control-sm" placeholder="Citation..."></textarea></div>
                    <div class="col-12"><button type="submit" class="btn btn-primary btn-sm">Ajouter témoignage</button></div>
                </form>
                <ul class="list-group list-group-flush small">
                    @foreach($testimonials as $testi)
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <div>
                            <strong>{{ $testi->author_name }}</strong> ({{ $testi->garage_name }})
                            <div class="text-muted">{{ $testi->quote }}</div>
                        </div>
                        <div class="d-flex gap-1">
                            <button wire:click="toggleTestimonial({{ $testi->id }})" class="btn btn-outline-secondary btn-sm">{{ $testi->is_active ? 'Masquer' : 'Afficher' }}</button>
                            <button wire:click="deleteTestimonial({{ $testi->id }})" class="btn btn-outline-danger btn-sm">Suppr.</button>
                        </div>
                    </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- 8. PAYS & INDICATIFS --}}
        @if($activeSection === 'countries')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Pays &amp; Indicatifs Téléphoniques</h1>
                <p class="text-muted small mb-0">Configurez les pays actifs sur la plateforme et le Marketplace</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4">
                <form wire:submit="addCountry" class="row g-2 mb-4">
                    <div class="col-md-2"><input wire:model="newCountryCode" type="text" maxlength="2" class="form-control form-control-sm text-uppercase" placeholder="Code (CM)"></div>
                    <div class="col-md-5"><input wire:model="newCountryName" type="text" class="form-control form-control-sm" placeholder="Nom du pays"></div>
                    <div class="col-md-3"><input wire:model="newCountryDialCode" type="text" class="form-control form-control-sm" placeholder="+237"></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Ajouter pays</button></div>
                </form>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light"><tr><th>Code</th><th>Pays</th><th>Indicatif</th><th class="text-end">Statut</th></tr></thead>
                        <tbody>
                            @foreach($countries as $c)
                            <tr>
                                <td><code>{{ $c->code }}</code></td>
                                <td>{{ $c->name }}</td>
                                <td>{{ $c->dial_code }}</td>
                                <td class="text-end">
                                    <button wire:click="toggleCountry({{ $c->id }})" class="btn btn-sm {{ $c->is_active ? 'btn-outline-success' : 'btn-outline-secondary' }}">
                                        {{ $c->is_active ? 'Actif' : 'Inactif' }}
                                    </button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- 7. SÉCURITÉ & BLOCAGE IP --}}
        @if($activeSection === 'security')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Sécurité &amp; Pare-feu IP</h1>
                <p class="text-muted small mb-0">Bloquez les adresses IP suspectes et consultez les journaux de sécurité</p>
            </div>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 p-4">
                        <h2 class="h6 fw-bold mb-3">Blocage manuel d'IP</h2>
                        <form wire:submit="blockIpManual" class="vstack gap-2 mb-3">
                            <input wire:model="newIp" type="text" class="form-control form-control-sm" placeholder="Adresse IP (ex: 192.168.1.50)">
                            <input wire:model="newIpReason" type="text" class="form-control form-control-sm" placeholder="Motif">
                            <button type="submit" class="btn btn-danger btn-sm">Bloquer l'IP</button>
                        </form>
                        <ul class="list-group list-group-flush small">
                            @forelse($blockedIps as $ip)
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span><code>{{ $ip->ip_address }}</code> ({{ $ip->reason }})</span>
                                <button wire:click="unblockIp({{ $ip->id }})" class="btn btn-outline-secondary btn-sm">Débloquer</button>
                            </li>
                            @empty
                            <li class="list-group-item px-0 text-muted">Aucune IP bloquée.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                        <div class="card-header bg-white fw-bold py-3">Journal d'audit récent</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light"><tr><th>Action</th><th>IP</th><th>Date</th></tr></thead>
                                <tbody>
                                    @foreach($auditLogs as $log)
                                    <tr>
                                        <td><code>{{ $log->action }}</code></td>
                                        <td class="small">{{ $log->ip_address }}</td>
                                        <td class="small text-muted">{{ $log->created_at?->format('d/m/Y H:i') }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- 9. ADMINISTRATEURS & RÔLES --}}
        @if($activeSection === 'admins')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Gestion des Administrateurs &amp; Rôles</h1>
                <p class="text-muted small mb-0">Créez et modifiez les administrateurs ; l'accès aux menus s'adapte automatiquement à leur rôle</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
                <h2 class="h6 fw-bold mb-3">{{ $editingAdminId ? 'Modifier un administrateur' : 'Créer un administrateur' }}</h2>
                <form wire:submit="saveAdminUser" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Nom complet *</label>
                        <input wire:model="newAdminName" type="text" class="form-control form-control-sm" placeholder="Ex: Sarah Support" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Email *</label>
                        <input wire:model="newAdminEmail" type="email" class="form-control form-control-sm" placeholder="sarah@admin.test" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Rôle *</label>
                        <select wire:model="newAdminRole" class="form-select form-select-sm">
                            @foreach($adminRoles as $roleKey => $roleDesc)
                                <option value="{{ $roleKey }}">{{ $roleDesc }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Sexe</label>
                        <select wire:model="newAdminGender" class="form-select form-select-sm">
                            <option value="">Non précisé</option>
                            <option value="homme">Homme</option>
                            <option value="femme">Femme</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Poste</label>
                        <input wire:model="newAdminJobTitle" type="text" class="form-control form-control-sm" placeholder="Ex: Responsable Support">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Téléphone</label>
                        <input wire:model="newAdminPhone" type="text" class="form-control form-control-sm" placeholder="+237 6...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Date de naissance (facultatif)</label>
                        <input
                            id="newAdminBirthDatePicker"
                            wire:model="newAdminBirthDate"
                            type="text"
                            class="form-control form-control-sm admin-datepicker"
                            placeholder="YYYY-MM-DD"
                            autocomplete="off"
                            onchange="@this.set('newAdminBirthDate', this.value)"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Photo (stockée dans storage/images)</label>
                        <input wire:model="newAdminAvatarFile" type="file" accept="image/*" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Mot de passe {{ $editingAdminId ? '(optionnel)' : '*' }}</label>
                        <div class="input-group input-group-sm">
                            <input id="newAdminPwdInput" wire:model="newAdminPassword" type="password" class="form-control" {{ $editingAdminId ? '' : 'required' }}>
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleAdminPwd('newAdminPwdInput', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Description (Bio)</label>
                        <textarea wire:model="newAdminBio" rows="1" class="form-control form-control-sm" placeholder="Courte biographie..."></textarea>
                    </div>

                    <div class="col-12 d-flex align-items-center gap-3">
                        <div class="form-check form-switch">
                            <input id="adminActiveSwitch" wire:model="newAdminActive" class="form-check-input" type="checkbox">
                            <label class="form-check-label small" for="adminActiveSwitch">Compte actif</label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm px-4">
                            {{ $editingAdminId ? 'Mettre à jour' : 'Créer l\'administrateur' }}
                        </button>
                        @if($editingAdminId)
                            <button type="button" wire:click="$set('editingAdminId', null)" class="btn btn-outline-secondary btn-sm">Annuler</button>
                        @endif
                    </div>
                </form>
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr><th>Administrateur</th><th>Poste &amp; Contact</th><th>Rôle &amp; Permissions</th><th>Statut</th><th class="text-end">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach($admins as $adm)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        @if($adm->avatarUrl())
                                            <img src="{{ $adm->avatarUrl() }}" alt="{{ $adm->name }}" class="rounded-circle border" style="width: 36px; height: 36px; object-fit: cover;">
                                        @else
                                            <span class="rounded-circle bg-primary text-white fw-bold d-inline-flex align-items-center justify-content-center" style="width: 36px; height: 36px; font-size: 0.8rem;">
                                                {{ strtoupper(substr($adm->name, 0, 1)) }}
                                            </span>
                                        @endif
                                        <div>
                                            <div class="fw-semibold">{{ $adm->name }}</div>
                                            <div class="small text-muted">{{ $adm->email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="small">
                                    <div>{{ $adm->job_title ?: '-' }} {{ $adm->gender ? '('.ucfirst($adm->gender).')' : '' }}</div>
                                    <div class="text-muted">{{ $adm->phone ?: '-' }}</div>
                                </td>
                                <td class="small"><span class="badge bg-primary-subtle text-primary">{{ $adm->roleLabel() }}</span></td>
                                <td><span class="badge {{ $adm->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $adm->is_active ? 'Actif' : 'Inactif' }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button wire:click="editAdminUser({{ $adm->id }})" class="btn btn-outline-primary btn-sm">Modifier</button>
                                    @if(auth('admin')->id() !== $adm->id)
                                        <button wire:click="toggleAdminUser({{ $adm->id }})" class="btn btn-outline-secondary btn-sm">{{ $adm->is_active ? 'Désactiver' : 'Activer' }}</button>
                                        <button wire:click="deleteAdminUser({{ $adm->id }})" class="btn btn-outline-danger btn-sm">Supprimer</button>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- 10. PARAMÈTRES & SEO + MAINTENANCE --}}
        @if($activeSection === 'settings')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Paramètres Globaux, Abonnements &amp; SEO</h1>
                <p class="text-muted small mb-0">Pilotez la durée d'essai, les tarifs, les commissions d'affiliation et l'identité du site</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4">
                <form wire:submit="saveSettings" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Prix mensuel (FCFA)</label>
                        <input wire:model="subscriptionPrice" type="number" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Durée essai (1 à 30 jours)</label>
                        <select wire:model="trialDays" class="form-select form-select-sm">
                            @for($d = 1; $d <= 30; $d++)
                                <option value="{{ $d }}">{{ $d }} {{ $d === 1 ? 'jour' : 'jours' }}{{ $d === 7 ? ' (par défaut)' : '' }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Délai de grâce (jours)</label>
                        <input wire:model="gracePeriodDays" type="number" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Commission Affiliation Parrain (FCFA)</label>
                        <input wire:model="affiliateCommission" type="number" min="0" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Réduction Filleul 1er Abonnement (FCFA)</label>
                        <input wire:model="affiliateReferralDiscount" type="number" min="0" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Versement commission parrain</label>
                        <select wire:model="affiliateCommissionTrigger" class="form-select form-select-sm">
                            <option value="first_payment">Au 1er paiement du filleul</option>
                            <option value="signup">Dès l'inscription du filleul</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Nom du site</label>
                        <input wire:model="siteName" type="text" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Téléphone de la structure</label>
                        <input wire:model="sitePhone" type="text" class="form-control form-control-sm" placeholder="+237 6...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Email de la structure (Contact)</label>
                        <input wire:model="structureEmail" type="email" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Téléphone WhatsApp</label>
                        <input wire:model="siteWhatsapp" type="text" class="form-control form-control-sm" placeholder="+237 6...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Email secondaire (facultatif)</label>
                        <input wire:model="siteSecondaryEmail" type="email" class="form-control form-control-sm" placeholder="support@...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Boîte postale (facultatif)</label>
                        <input wire:model="sitePoBox" type="text" class="form-control form-control-sm" placeholder="BP 1234 Douala">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Lien Facebook (facultatif)</label>
                        <input wire:model="socialFacebook" type="url" class="form-control form-control-sm" placeholder="https://facebook.com/...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Lien LinkedIn (facultatif)</label>
                        <input wire:model="socialLinkedin" type="url" class="form-control form-control-sm" placeholder="https://linkedin.com/company/...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Lien Twitter / X (facultatif)</label>
                        <input wire:model="socialTwitter" type="url" class="form-control form-control-sm" placeholder="https://x.com/...">
                    </div>

                    <div class="col-12 border-top pt-3 mt-2">
                        <div class="p-3 rounded-3 border {{ $maintenanceMode ? 'bg-danger bg-opacity-10 border-danger' : 'bg-light' }}">
                            <div class="form-check form-switch mb-2">
                                <input id="maintenanceSwitch" wire:model="maintenanceMode" class="form-check-input" type="checkbox">
                                <label class="form-check-label fw-bold text-dark" for="maintenanceSwitch">
                                    <i class="bi bi-tools text-danger me-1"></i>Activer le Mode Maintenance sur tout le site (hors Admin)
                                </label>
                            </div>
                            <label class="form-label small text-muted mb-1">Message affiché aux visiteurs pendant la maintenance</label>
                            <textarea wire:model="maintenanceMessage" rows="2" class="form-control form-control-sm"></textarea>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Description SEO</label>
                        <textarea wire:model="siteDescription" rows="2" class="form-control form-control-sm"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Mots-clés SEO</label>
                        <textarea wire:model="siteKeywords" rows="2" class="form-control form-control-sm" placeholder="garage, pièces auto, afrique..."></textarea>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Logo du site</label>
                        <input wire:model="siteLogoFile" type="file" accept="image/*" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Favicon</label>
                        <input wire:model="siteFaviconFile" type="file" accept="image/*" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Image SEO (OpenGraph)</label>
                        <input wire:model="seoImageFile" type="file" accept="image/*" class="form-control form-control-sm">
                    </div>

                    <div class="col-12 pt-2">
                        <button type="submit" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-check2-circle me-1"></i>Enregistrer les paramètres
                        </button>
                    </div>
                </form>
            </div>
        @endif

        {{-- 9. MON PROFIL ADMIN --}}
        @if($activeSection === 'profile')
            @php
                $adminUser = auth('admin')->user();
                $avatarPreview = $profileAvatarFile ? $profileAvatarFile->temporaryUrl() : $adminUser?->avatarUrl();
            @endphp
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Modifier mon profil Administrateur</h1>
                <p class="text-muted small mb-0">Mettez à jour votre photo, informations personnelles et mot de passe</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4" style="max-width: 820px;">
                <form wire:submit="saveAdminProfile" class="row g-3">
                    <div class="col-12 d-flex align-items-center gap-3 pb-3 border-bottom">
                        @if($avatarPreview)
                            <img src="{{ $avatarPreview }}" alt="{{ $adminUser->name }}" class="rounded-circle border shadow-sm" style="width: 76px; height: 76px; object-fit: cover;">
                        @else
                            <span class="rounded-circle bg-primary text-white fw-bold d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 76px; height: 76px; font-size: 1.5rem;">
                                {{ strtoupper(substr($adminUser->name ?? 'A', 0, 1)) }}
                            </span>
                        @endif
                        <div class="flex-grow-1">
                            <label class="form-label small fw-semibold mb-1">Photo de profil (storage/images)</label>
                            <input wire:model="profileAvatarFile" type="file" accept="image/*" class="form-control form-control-sm">
                            <div class="form-text small">JPG, PNG ou WebP (max 3 Mo).</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nom complet *</label>
                        <input wire:model="profileName" type="text" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Adresse e-mail *</label>
                        <input wire:model="profileEmail" type="email" class="form-control form-control-sm" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Sexe</label>
                        <select wire:model="profileGender" class="form-select form-select-sm">
                            <option value="">Non précisé</option>
                            <option value="homme">Homme</option>
                            <option value="femme">Femme</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Poste</label>
                        <input wire:model="profileJobTitle" type="text" class="form-control form-control-sm" placeholder="Ex: Super-Admin">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Téléphone</label>
                        <input wire:model="profilePhone" type="text" class="form-control form-control-sm" placeholder="+237 6...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Date de naissance (facultatif)</label>
                        <input
                            id="profileBirthDatePicker"
                            wire:model="profileBirthDate"
                            type="text"
                            class="form-control form-control-sm admin-datepicker"
                            placeholder="YYYY-MM-DD"
                            autocomplete="off"
                            onchange="@this.set('profileBirthDate', this.value)"
                        >
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold">Description (Bio)</label>
                        <textarea wire:model="profileBio" rows="2" class="form-control form-control-sm" placeholder="Votre biographie..."></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nouveau mot de passe (optionnel)</label>
                        <div class="input-group input-group-sm">
                            <input id="profilePwdInput" wire:model="profilePassword" type="password" class="form-control" placeholder="Laisser vide pour ne pas changer">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleAdminPwd('profilePwdInput', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Confirmer le mot de passe</label>
                        <div class="input-group input-group-sm">
                            <input id="profilePwdConfInput" wire:model="profilePasswordConfirmation" type="password" class="form-control">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleAdminPwd('profilePwdConfInput', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-12 pt-2">
                        <button type="submit" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-check2-circle me-1"></i>Enregistrer mon profil
                        </button>
                    </div>
                </form>
            </div>
        @endif

        {{-- 10. CONDITIONS D'UTILISATION --}}
        @if($activeSection === 'terms')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Conditions d'utilisation</h1>
                <p class="text-muted small mb-0">Rédigez et mettez en forme les conditions générales affichées dans le pied de page du site</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4">
                <form wire:submit="saveTerms" class="vstack gap-3">
                    @include('admin.partials.wysiwyg', ['field' => 'termsContent', 'value' => $termsContent])
                    <div>
                        <button type="submit" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-check2-circle me-1"></i>Enregistrer les Conditions d'utilisation
                        </button>
                        <a href="{{ route('legal.terms') }}" target="_blank" class="btn btn-outline-secondary ms-2">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Voir la page publique
                        </a>
                    </div>
                </form>
            </div>
        @endif

        {{-- 11. POLITIQUE DE CONFIDENTIALITÉ --}}
        @if($activeSection === 'privacy')
            <div class="mb-4">
                <h1 class="h4 fw-bold mb-0">Politique de confidentialité</h1>
                <p class="text-muted small mb-0">Rédigez et mettez en forme la politique de confidentialité affichée dans le pied de page du site</p>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4">
                <form wire:submit="savePrivacy" class="vstack gap-3">
                    @include('admin.partials.wysiwyg', ['field' => 'privacyContent', 'value' => $privacyContent])
                    <div>
                        <button type="submit" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-check2-circle me-1"></i>Enregistrer la Politique de confidentialité
                        </button>
                        <a href="{{ route('legal.privacy') }}" target="_blank" class="btn btn-outline-secondary ms-2">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Voir la page publique
                        </a>
                    </div>
                </form>
            </div>
        @endif
    </div>
</div>
