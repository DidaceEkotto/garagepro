<script setup>
import PhoneInput from '@/Components/PhoneInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

defineProps({
    agencies: Array,
    activeAgencyId: Number,
    canManage: Boolean,
});

const editingAgency = ref(null);

const form = useForm({
    name: '',
    code: '',
    phone: '',
    city: '',
    address: '',
});

const editForm = useForm({
    name: '',
    code: '',
    phone: '',
    city: '',
    address: '',
    is_active: true,
});

function createAgency() {
    form.post('/agences', {
        onSuccess: () => {
            form.reset();
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddAgency'))?.hide();
        },
    });
}

async function openEditModal(agency) {
    editingAgency.value = agency;
    editForm.clearErrors();
    editForm.name = agency.name || '';
    editForm.code = agency.code || '';
    editForm.phone = agency.phone || '';
    editForm.city = agency.city || '';
    editForm.address = agency.address || '';
    editForm.is_active = Boolean(agency.is_active);
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalEditAgency'))?.show();
}

function updateAgency() {
    if (!editingAgency.value) return;
    editForm.put(`/agences/${editingAgency.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingAgency.value = null;
            window.bootstrap?.Modal.getInstance(document.getElementById('modalEditAgency'))?.hide();
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
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Agences &amp; Succursales</h1>
                <p class="text-muted small mb-0">Gérez vos agences, modifiez leurs informations et sélectionnez l'agence active</p>
            </div>
            <button v-if="canManage" type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddAgency">
                <i class="bi bi-building-add me-1"></i>Nouvelle agence
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nom</th>
                            <th>Code</th>
                            <th>Ville &amp; Adresse</th>
                            <th>Téléphone</th>
                            <th>Statut</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="agency in agencies" :key="agency.id">
                            <td class="fw-semibold">
                                {{ agency.name }}
                                <span v-if="agency.is_main" class="badge bg-primary ms-1">Principale</span>
                            </td>
                            <td><code>{{ agency.code ?? '-' }}</code></td>
                            <td class="small">
                                <div>{{ agency.city ?? '-' }}</div>
                                <div v-if="agency.address" class="text-muted">{{ agency.address }}</div>
                            </td>
                            <td class="small text-muted">{{ agency.phone ?? '-' }}</td>
                            <td>
                                <span class="badge" :class="agency.is_active ? 'bg-success' : 'bg-secondary'">
                                    {{ agency.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                <button
                                    v-if="canManage"
                                    type="button"
                                    class="btn btn-outline-secondary btn-sm me-1"
                                    title="Modifier cette agence"
                                    @click="openEditModal(agency)"
                                >
                                    <i class="bi bi-pencil me-1"></i>Modifier
                                </button>
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

        <!-- Modal Création Agence -->
        <StaticModal id="modalAddAgency" title="Nouvelle agence">
            <form @submit.prevent="createAgency">
                <div v-if="Object.keys(form.errors).length" class="alert alert-danger py-2 small mb-3">
                    <div v-for="(err, field) in form.errors" :key="field">{{ err }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nom de l'agence <span class="text-danger">*</span></label>
                    <input v-model="form.name" type="text" class="form-control" placeholder="Ex: Agence Akwa" required />
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label small fw-semibold">Code</label>
                        <input v-model="form.code" type="text" class="form-control" placeholder="AG02" />
                    </div>
                    <div class="col-7">
                        <label class="form-label small fw-semibold">Ville</label>
                        <input v-model="form.city" type="text" class="form-control" placeholder="Douala" />
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Téléphone</label>
                    <PhoneInput v-model="form.phone" :is-invalid="!!form.errors.phone" />
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Adresse</label>
                    <input v-model="form.address" type="text" class="form-control" />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Créer l'agence</button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal Modification Agence -->
        <StaticModal id="modalEditAgency" title="Modifier l'agence">
            <form v-if="editingAgency" @submit.prevent="updateAgency">
                <div v-if="Object.keys(editForm.errors).length" class="alert alert-danger py-2 small mb-3">
                    <div v-for="(err, field) in editForm.errors" :key="field">{{ err }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nom de l'agence <span class="text-danger">*</span></label>
                    <input v-model="editForm.name" type="text" class="form-control" required />
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label small fw-semibold">Code</label>
                        <input v-model="editForm.code" type="text" class="form-control" />
                    </div>
                    <div class="col-7">
                        <label class="form-label small fw-semibold">Ville</label>
                        <input v-model="editForm.city" type="text" class="form-control" />
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Téléphone</label>
                    <PhoneInput v-model="editForm.phone" :is-invalid="!!editForm.errors.phone" />
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Adresse</label>
                    <input v-model="editForm.address" type="text" class="form-control" />
                </div>
                <div v-if="!editingAgency.is_main" class="form-check form-switch mb-4">
                    <input id="editAgencyActive" v-model="editForm.is_active" class="form-check-input" type="checkbox" />
                    <label class="form-check-label small" for="editAgencyActive">Agence active</label>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="editForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
