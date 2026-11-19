<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    payment: Object,
    message: String,
});
</script>

<template>
    <Head title="Statut du paiement" />
    <TenantLayout>
        <div class="row justify-content-center py-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm text-center p-4">
                    <h1 class="h4 fw-bold mb-3">Suivi de votre paiement</h1>
                    <p class="mb-3">{{ message }}</p>
                    <p class="small text-muted">
                        Référence : <code>{{ payment.reference_code }}</code> —
                        Montant : {{ new Intl.NumberFormat('fr-FR').format(payment.amount) }} {{ payment.currency }}
                    </p>
                    <div class="d-flex justify-content-center gap-2 mt-3">
                        <Link href="/tableau-de-bord" class="btn btn-primary">Tableau de bord</Link>
                        <Link v-if="payment.status === 'failed' || payment.status === 'cancelled'" href="/abonnement" class="btn btn-outline-primary">
                            Réessayer
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
