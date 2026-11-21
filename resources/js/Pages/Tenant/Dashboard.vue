<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    tenantType: { type: String, default: 'garage' },
    technicalKpis: { type: Object, default: null },
    insuranceKpis: { type: Object, default: null },
    kpis: Object,
    revenueSeries: Array,
    ordersByStatus: Array,
    recentOrders: Array,
});

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}

const maxRevenue = computed(() => {
    const vals = (props.revenueSeries ?? []).map((r) => r.amount);
    return Math.max(1, ...vals);
});

const totalOrdersCount = computed(() => {
    const sum = (props.ordersByStatus ?? []).reduce((acc, s) => acc + s.count, 0);
    return Math.max(1, sum);
});

const statusColors = [
    'bg-secondary',
    'bg-info',
    'bg-primary',
    'bg-warning',
    'bg-dark',
    'bg-success',
    'bg-success bg-opacity-75',
];
</script>

<template>
    <Head title="Tableau de bord" />

    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">
                    {{ tenantType === 'centre_technique' ? 'Tableau de bord — Centre Technique' : (tenantType === 'assurance' ? 'Tableau de bord — Assurance Auto' : 'Tableau de bord récapitulatif') }}
                </h1>
                <p class="text-muted small mb-0">
                    {{ tenantType === 'centre_technique' ? 'Suivi des visites techniques, contre-visites et encaissements' : (tenantType === 'assurance' ? 'Suivi des polices, dossiers sinistres et accords de prise en charge' : 'Vue d\'ensemble de l\'activité atelier, stock et finances') }}
                </p>
            </div>
            <div v-if="tenantType === 'centre_technique'" class="d-flex gap-2">
                <Link href="/visite-technique" class="btn btn-primary btn-sm">
                    <i class="bi bi-clipboard2-check me-1"></i>Nouveau PV Visite
                </Link>
            </div>
            <div v-else-if="tenantType === 'assurance'" class="d-flex gap-2">
                <Link href="/assurances" class="btn btn-primary btn-sm">
                    <i class="bi bi-shield-check me-1"></i>Polices &amp; Sinistres
                </Link>
            </div>
            <div v-else class="d-flex gap-2">
                <Link href="/rendez-vous" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-calendar-plus me-1"></i>Nouveau RDV
                </Link>
                <Link href="/ordres-reparation" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>Nouvel OR
                </Link>
            </div>
        </div>

        <!-- KPIs Centre Technique -->
        <div v-if="tenantType === 'centre_technique' && technicalKpis" class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3">
                    <div class="small text-muted">PV Établis</div>
                    <div class="h4 fw-bold mb-0">{{ technicalKpis.totalPvs }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3">
                    <div class="small text-muted">Favorables (Aptes)</div>
                    <div class="h4 fw-bold text-success mb-0">{{ technicalKpis.favorableCount }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3">
                    <div class="small text-muted">Contre-visites</div>
                    <div class="h4 fw-bold text-warning mb-0">{{ technicalKpis.counterVisitCount }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3">
                    <div class="small text-muted">Immobilisations</div>
                    <div class="h4 fw-bold text-danger mb-0">{{ technicalKpis.immobilizedCount }}</div>
                </div>
            </div>
        </div>

        <!-- KPIs Assurance -->
        <div v-if="tenantType === 'assurance' && insuranceKpis" class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3">
                    <div class="small text-muted">Polices Actives</div>
                    <div class="h4 fw-bold text-primary mb-0">{{ insuranceKpis.activePolicies }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3">
                    <div class="small text-muted">Sinistres à traiter</div>
                    <div class="h4 fw-bold text-warning mb-0">{{ insuranceKpis.openClaims }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3">
                    <div class="small text-muted">Prises en charge accordées</div>
                    <div class="h4 fw-bold text-success mb-0">{{ insuranceKpis.approvedClaims }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm p-3">
                    <div class="small text-muted">Montant total approuvé</div>
                    <div class="h4 fw-bold mb-0">{{ fmt(insuranceKpis.approvedTotal) }}</div>
                </div>
            </div>
        </div>

        <!-- KPIs -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">CA du mois</span>
                            <i class="bi bi-cash-coin text-success fs-5"></i>
                        </div>
                        <div class="h5 fw-bold text-success mb-0">{{ fmt(kpis?.monthRevenue) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">OR en cours</span>
                            <i class="bi bi-wrench-adjustable text-primary fs-5"></i>
                        </div>
                        <div class="h5 fw-bold mb-0">{{ kpis?.activeOrdersCount ?? 0 }}</div>
                        <div class="small text-muted">{{ kpis?.receptionsMonth ?? 0 }} véhicule(s) reçu(s) ce mois</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">Impayés clients</span>
                            <i class="bi bi-exclamation-circle text-danger fs-5"></i>
                        </div>
                        <div class="h5 fw-bold text-danger mb-0">{{ fmt(kpis?.unpaidTotal) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">Alertes stock &amp; Clients</span>
                            <i class="bi bi-box-seam text-warning fs-5"></i>
                        </div>
                        <div class="h5 fw-bold mb-0">{{ kpis?.lowStockCount ?? 0 }} alerte(s)</div>
                        <div class="small text-muted">{{ kpis?.customersCount ?? 0 }} client(s) actif(s)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Graphiques -->
        <div class="row g-4 mb-4">
            <!-- Graphique en barres CA 6 mois -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                        <span>Chiffre d'affaires (6 derniers mois)</span>
                        <Link href="/rapports" class="small text-decoration-none">Voir rapports</Link>
                    </div>
                    <div class="card-body d-flex align-items-end gap-3 pt-4" style="min-height: 240px;">
                        <div
                            v-for="bar in revenueSeries"
                            :key="bar.label"
                            class="flex-fill d-flex flex-column align-items-center justify-content-end h-100"
                        >
                            <div class="small text-muted mb-1" style="font-size: 0.7rem;">
                                {{ bar.amount > 0 ? new Intl.NumberFormat('fr-FR').format(bar.amount) : '0' }}
                            </div>
                            <div
                                class="w-100 bg-primary rounded-top"
                                :style="{
                                    height: Math.max(6, Math.round((bar.amount / maxRevenue) * 150)) + 'px',
                                    transition: 'height 0.3s ease',
                                }"
                            ></div>
                            <div class="small text-muted mt-2 text-center" style="font-size: 0.75rem;">
                                {{ bar.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Répartition des OR par statut -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                        <span>Activité Atelier par statut</span>
                        <Link href="/ordres-reparation" class="small text-decoration-none">Tous les OR</Link>
                    </div>
                    <div class="card-body">
                        <div v-for="(item, idx) in ordersByStatus" :key="item.status" class="mb-2">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>{{ item.label }}</span>
                                <span class="fw-semibold">{{ item.count }}</span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div
                                    class="progress-bar"
                                    :class="statusColors[idx % statusColors.length]"
                                    :style="{ width: Math.round((item.count / totalOrdersCount) * 100) + '%' }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Derniers OR -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span>Derniers Ordres de Réparation</span>
                <Link href="/ordres-reparation" class="small text-decoration-none">Gérer</Link>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Numéro OR</th>
                            <th>Client</th>
                            <th>Véhicule</th>
                            <th>Statut</th>
                            <th class="text-end">Total TTC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!recentOrders?.length">
                            <td colspan="5" class="text-center text-muted py-4 small">Aucun ordre de réparation pour le moment.</td>
                        </tr>
                        <tr v-for="o in recentOrders" :key="o.id">
                            <td class="fw-bold">{{ o.order_number }}</td>
                            <td>{{ o.customer?.name }}</td>
                            <td>{{ o.vehicle?.license_plate }}</td>
                            <td><span class="badge bg-primary">{{ o.status }}</span></td>
                            <td class="text-end">{{ fmt(o.total_ttc) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </TenantLayout>
</template>
