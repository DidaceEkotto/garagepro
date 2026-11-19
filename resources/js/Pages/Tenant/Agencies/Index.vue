<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps({
    agencies: Array,
    activeAgencyId: Number,
    canManage: Boolean,
});

const form = useForm({
    name: '',
    code: '',
    phone: '',
    city: '',
    address: '',
});

function createAgency() {
    form.post('/agences', {
        onSuccess: () => {
            form.reset();
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddAgency'))?.hide();
        },
    });
}

function switchAgency(id) {
    router.post(`/agences/${id}/activer`);
}
</script>

<template>
    <Head title="Agences" />

    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Agences du garage</h1>
                <p class="text-muted small mb-0">Gérez vos succursales et sélectionnez l'agence active</p>
            </div>
            <button v-if="canManage" type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddAgency">
                <i class="bi bi-building-add me-1"></i>Nouvelle agence
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nom</th>
                            <th>Code</th>
                            <th>Ville</th>
                            <th>Statut</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="agency in agencies" :key="agency.id">
                            <td class="fw-semibold">
                                {{ agency.name }}
                                <span v-if="agency.is_main" class="badge bg-primary ms-1">Principale</span>
                            </td>
                            <td><code>{{ agency.code ?? '-' }}</code></td>
                            <td>{{ agency.city ?? '-' }}</td>
                            <td>
                                <span class="badge" :class="agency.is_active ? 'bg-success' : 'bg-secondary'">
                                    {{ agency.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <span v-if="agency.id === activeAgencyId" class="badge bg-info text-dark">En cours</span>
                                <button v-else type="button" class="btn btn-outline-primary btn-sm" @click="switchAgency(agency.id)">
                                    Sélectionner
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalAddAgency" title="Nouvelle agence">
            <form @submit.prevent="createAgency">
                <div class="mb-3">
                    <label class="form-label small">Nom de l'agence</label>
                    <input v-model="form.name" type="text" class="form-control" placeholder="Ex: Agence Akwa" required />
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label small">Code</label>
                        <input v-model="form.code" type="text" class="form-control" placeholder="AG02" />
                    </div>
                    <div class="col-7">
                        <label class="form-label small">Ville</label>
                        <input v-model="form.city" type="text" class="form-control" placeholder="Douala" />
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Téléphone</label>
                    <input v-model="form.phone" type="text" class="form-control" />
                </div>
                <div class="mb-4">
                    <label class="form-label small">Adresse</label>
                    <input v-model="form.address" type="text" class="form-control" />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Créer l'agence</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
