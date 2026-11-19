<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    customers: Object,
    search: String,
});

const query = ref(props.search ?? '');
const remindingCustomer = ref(null);

const form = useForm({
    name: '',
    phone: '',
    email: '',
    type: 'particulier',
    city: '',
    company_name: '',
    credit_limit: 0,
});

const remindForm = useForm({
    subject: 'Suivi de votre véhicule',
    message: 'Bonjour, nous vous contactons concernant votre véhicule ou un rendez-vous au sein de notre atelier. N\'hésitez pas à nous répondre ou à passer nous voir.',
});

function openRemind(customer) {
    remindingCustomer.value = customer;
    remindForm.subject = 'Suivi / Relance client';
    remindForm.message = `Bonjour ${customer.name},\n\nNous vous contactons pour le suivi de votre dossier au garage. N'hésitez pas à nous contacter pour toute information.\n\nCordialement.`;
}

function submitRemind() {
    if (!remindingCustomer.value) return;
    remindForm.post(`/clients/${remindingCustomer.value.id}/relancer`, {
        onSuccess: () => {
            remindingCustomer.value = null;
            window.bootstrap?.Modal.getInstance(document.getElementById('modalRemindCustomer'))?.hide();
        },
    });
}

function doSearch() {
    router.get('/clients', { q: query.value }, { preserveState: true });
}

function createCustomer() {
    form.post('/clients', {
        onSuccess: () => {
            form.reset();
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddCustomer'))?.hide();
        },
    });
}
</script>

<template>
    <Head title="Clients & Véhicules" />

    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Clients &amp; Véhicules</h1>
                <p class="text-muted small mb-0">Recherche rapide par nom, téléphone ou plaque d'immatriculation</p>
            </div>
            <div class="d-flex gap-2">
                <a href="/export-excel/clients" class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
                </a>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddCustomer">
                    <i class="bi bi-person-plus me-1"></i>Nouveau client
                </button>
            </div>
        </div>

        <form class="input-group mb-3 shadow-sm" @submit.prevent="doSearch">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
            <input v-model="query" type="search" class="form-control border-start-0" placeholder="Rechercher par nom, téléphone ou immatriculation..." />
            <button class="btn btn-primary px-4" type="submit">Rechercher</button>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nom</th>
                            <th>Téléphone</th>
                            <th>Type</th>
                            <th>Véhicules</th>
                            <th class="text-end">Fiche</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in customers.data" :key="c.id">
                            <td class="fw-semibold">{{ c.name }}</td>
                            <td>{{ c.phone }}</td>
                            <td><span class="badge bg-secondary">{{ c.type }}</span></td>
                            <td>{{ c.vehicles?.length ?? 0 }}</td>
                            <td class="text-end text-nowrap">
                                <button
                                    v-if="c.email"
                                    type="button"
                                    class="btn btn-outline-info btn-sm me-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalRemindCustomer"
                                    @click="openRemind(c)"
                                >
                                    <i class="bi bi-envelope-paper me-1"></i>Relancer
                                </button>
                                <Link :href="`/clients/${c.id}`" class="btn btn-outline-primary btn-sm">Ouvrir</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalAddCustomer" title="Nouveau client">
            <form @submit.prevent="createCustomer">
                <div class="mb-3">
                    <label class="form-label small">Nom complet</label>
                    <input v-model="form.name" type="text" class="form-control" required />
                </div>
                <div class="mb-3">
                    <label class="form-label small">Téléphone</label>
                    <input v-model="form.phone" type="text" class="form-control" required />
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small">Type</label>
                        <select v-model="form.type" class="form-select">
                            <option value="particulier">Particulier</option>
                            <option value="entreprise">Entreprise</option>
                            <option value="flotte">Flotte</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Ville</label>
                        <input v-model="form.city" type="text" class="form-control" />
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small">E-mail (optionnel)</label>
                    <input v-model="form.email" type="email" class="form-control" />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalRemindCustomer" title="Relancer le client par e-mail">
            <form @submit.prevent="submitRemind">
                <p class="small text-muted mb-3">
                    Destinataire : <strong>{{ remindingCustomer?.name }}</strong> ({{ remindingCustomer?.email }})
                </p>
                <div class="mb-3">
                    <label class="form-label small">Objet</label>
                    <input v-model="remindForm.subject" type="text" class="form-control" required />
                </div>
                <div class="mb-4">
                    <label class="form-label small">Message</label>
                    <textarea v-model="remindForm.message" rows="4" class="form-control" required></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="remindForm.processing">
                        <i class="bi bi-send me-1"></i>Envoyer la relance
                    </button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
