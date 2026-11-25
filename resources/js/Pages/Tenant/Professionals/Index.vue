<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import StaticModal from '@/Components/StaticModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useLightbox } from '@/composables/useLightbox';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { openLightbox } = useLightbox();

const props = defineProps({
    professionals: Array,
    agencies: Array,
    availableRoles: Array,
    canManage: Boolean,
});

const showPassword = ref(false);

const roleAccessMap = {
    gerant: {
        allowed: 'Tous les menus (Atelier, Stock, Facturation, Comptabilité, RH, Rapports, Paramètres)',
        restricted: 'Aucune restriction',
    },
    receptionniste: {
        allowed: 'Tableau de bord, Clients & Véhicules, Rendez-vous, Réceptions, Devis, Facturation, Après-vente, Messagerie',
        restricted: 'Stock, Approvisionnement, Comptabilité, Rapports, RH, Paramètres Espace',
    },
    chef_atelier: {
        allowed: 'Tableau de bord, Clients, Rendez-vous, Réceptions, Ordres de réparation, Devis, Atelier, Stock (lecture), Messagerie',
        restricted: 'Facturation, Comptabilité, Rapports, RH, Paramètres Espace',
    },
    mecanicien: {
        allowed: 'Tableau de bord, Atelier (Mes OR), Stock (consultation), Messagerie',
        restricted: 'Clients, Réceptions, Facturation, Comptabilité, Rapports, RH, Paramètres Espace',
    },
    magasinier: {
        allowed: 'Tableau de bord, Ordres de réparation (lecture), Stock & Pièces, Approvisionnement, Messagerie',
        restricted: 'Clients, Facturation, Comptabilité, Rapports, RH, Paramètres Espace',
    },
    comptable: {
        allowed: 'Tableau de bord, Clients (lecture), Devis (lecture), Facturation, Comptabilité OHADA, Rapports, Messagerie',
        restricted: 'Réceptions, Atelier, Stock, RH, Paramètres Espace',
    },
};

const form = useForm({
    name: '',
    email: '',
    phone: '',
    role: 'mecanicien',
    agency_id: props.agencies?.[0]?.id ?? '',
    gender: 'M',
    marital_status: 'celibataire',
    birth_date: '',
    birth_place: '',
    base_salary: 150000,
    commission_rate: 5,
    photo: null,
    password: '',
});

function createProfessional() {
    form.post('/professionnels', {
        forceFormData: true,
        onSuccess: () => {
            form.reset('name', 'email', 'phone', 'birth_date', 'birth_place', 'photo', 'password');
            const modalEl = document.getElementById('modalAddProfessional');
            if (modalEl && window.bootstrap) {
                window.bootstrap.Modal.getInstance(modalEl)?.hide();
            }
        },
    });
}

const editingId = ref(null);

const editForm = useForm({
    _method: 'PUT',
    name: '',
    email: '',
    phone: '',
    role: 'mecanicien',
    agency_id: '',
    gender: 'M',
    marital_status: 'celibataire',
    birth_date: '',
    birth_place: '',
    base_salary: 0,
    commission_rate: 0,
    photo: null,
});

function openEdit(p) {
    editingId.value = p.id;
    editForm.name = p.name;
    editForm.email = p.email;
    editForm.phone = p.phone ?? '';
    editForm.role = p.role ?? p.roles?.[0] ?? 'mecanicien';
    editForm.agency_id = p.default_agency_id ?? props.agencies?.[0]?.id ?? '';
    editForm.gender = p.gender ?? 'M';
    editForm.marital_status = p.marital_status ?? 'celibataire';
    editForm.birth_date = p.birth_date ?? '';
    editForm.birth_place = p.birth_place ?? '';
    editForm.base_salary = p.base_salary ?? 0;
    editForm.commission_rate = p.commission_rate ?? 0;
    editForm.photo = null;
}

function updateProfessional() {
    if (!editingId.value) return;
    editForm.post(`/professionnels/${editingId.value}`, {
        forceFormData: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalEditProfessional'))?.hide();
        },
    });
}

const { askConfirm } = useConfirm();

function toggleActive(id) {
    askConfirm({
        title: 'Changer le statut du compte',
        message: 'Voulez-vous vraiment activer ou suspendre ce compte professionnel ?',
        confirmLabel: 'Confirmer',
        confirmClass: 'btn-primary',
        onConfirm: () => router.post(`/professionnels/${id}/statut`),
    });
}

function resetPassword(p) {
    askConfirm({
        title: 'Réinitialiser le mot de passe',
        message: `Voulez-vous vraiment réinitialiser le mot de passe de ${p.name} à 00000000 ?`,
        confirmLabel: 'Réinitialiser',
        confirmClass: 'btn-warning text-dark',
        onConfirm: () => router.post(`/professionnels/${p.id}/reinitialiser-mot-de-passe`),
    });
}
</script>

<template>
    <Head title="Professionnels" />

    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Professionnels &amp; Équipe</h1>
                <p class="text-muted small mb-0">Comptes utilisateurs et fiches RH complètes (salaire, commissions, état civil)</p>
            </div>
            <button
                v-if="canManage"
                type="button"
                class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#modalAddProfessional"
            >
                <i class="bi bi-person-plus me-1"></i>Ajouter un professionnel
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Professionnel</th>
                                <th>Rôle &amp; Agence</th>
                                <th>État civil</th>
                                <th class="text-end">Salaire / Com.</th>
                                <th>Statut</th>
                                <th v-if="canManage" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in professionals" :key="p.id">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <img
                                            v-if="p.photo_url"
                                            :src="p.photo_url"
                                            :alt="p.name"
                                            class="rounded-circle border"
                                            style="width: 36px; height: 36px; object-fit: cover; cursor: zoom-in;"
                                            title="Cliquer pour agrandir"
                                            @click="openLightbox([p.photo_url], 0, p.name)"
                                        />
                                        <div v-else class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px;">
                                            {{ p.name?.charAt(0)?.toUpperCase() }}
                                        </div>
                                        <div>
                                            <div class="fw-semibold">{{ p.name }}</div>
                                            <div class="small text-muted">{{ p.email }} • {{ p.phone ?? '-' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span v-for="r in p.roles" :key="r" class="badge bg-primary me-1">{{ r }}</span>
                                    <div class="small text-muted">{{ p.default_agency ?? '-' }}</div>
                                </td>
                                <td class="small">
                                    <div>{{ p.gender === 'F' ? 'Féminin' : (p.gender === 'M' ? 'Masculin' : '-') }} • {{ p.marital_status ?? '-' }}</div>
                                    <div class="text-muted">{{ p.birth_date ?? '' }} {{ p.birth_place ? 'à ' + p.birth_place : '' }}</div>
                                </td>
                                <td class="text-end small">
                                    <div class="fw-semibold">{{ new Intl.NumberFormat('fr-FR').format(p.base_salary ?? 0) }} FCFA</div>
                                    <div class="text-muted">{{ p.commission_rate ?? 0 }} % com.</div>
                                </td>
                                <td>
                                    <span class="badge" :class="p.is_active ? 'bg-success' : 'bg-secondary'">
                                        {{ p.is_active ? 'Actif' : 'Inactif' }}
                                    </span>
                                </td>
                                <td v-if="canManage" class="text-end text-nowrap">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary btn-sm me-1"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalEditProfessional"
                                        @click="openEdit(p)"
                                    >
                                        Modifier
                                    </button>
                                    <button type="button" class="btn btn-outline-warning btn-sm me-1" title="Réinitialiser à 00000000" @click="resetPassword(p)">
                                        MDP → 00000000
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="toggleActive(p.id)">
                                        {{ p.is_active ? 'Désactiver' : 'Activer' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Static Modal : Ajouter un professionnel -->
        <StaticModal id="modalAddProfessional" title="Nouveau professionnel (Compte + Fiche RH)" size="modal-lg">
            <form @submit.prevent="createProfessional">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Nom complet</label>
                        <input v-model="form.name" type="text" class="form-control" :class="{ 'is-invalid': form.errors.name }" required />
                        <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Adresse e-mail</label>
                        <input v-model="form.email" type="email" class="form-control" :class="{ 'is-invalid': form.errors.email }" required />
                        <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Téléphone</label>
                        <input v-model="form.phone" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Rôle métier</label>
                        <select v-model="form.role" class="form-select">
                            <option v-for="r in availableRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Agence</label>
                        <select v-model="form.agency_id" class="form-select" required>
                            <option v-for="a in agencies" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="roleAccessMap[form.role]" class="alert alert-info py-2 px-3 small mb-3">
                    <div class="fw-bold mb-1"><i class="bi bi-shield-check me-1"></i>Accès au menu de navigation pour ce rôle :</div>
                    <div class="text-success"><i class="bi bi-check-circle me-1"></i><strong>Autorisé :</strong> {{ roleAccessMap[form.role].allowed }}</div>
                    <div class="text-muted"><i class="bi bi-lock me-1"></i><strong>Masqué / Restreint :</strong> {{ roleAccessMap[form.role].restricted }}</div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Sexe</label>
                        <select v-model="form.gender" class="form-select">
                            <option value="M">Masculin</option>
                            <option value="F">Féminin</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Statut matrimonial</label>
                        <select v-model="form.marital_status" class="form-select">
                            <option value="celibataire">Célibataire</option>
                            <option value="marie">Marié(e)</option>
                            <option value="divorce">Divorcé(e)</option>
                            <option value="veuf">Veuf / Veuve</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Date de naissance</label>
                        <DatePickerInput v-model="form.birth_date" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Lieu de naissance</label>
                        <input v-model="form.birth_place" type="text" class="form-control" placeholder="Douala" />
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Salaire de base (FCFA)</label>
                        <input v-model.number="form.base_salary" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Commission (%)</label>
                        <input v-model="form.commission_rate" type="number" step="0.5" min="0" max="100" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Photo (facultatif)</label>
                        <FileDropzone v-model="form.photo" label="Glisser-déposer une photo" />
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small">Mot de passe initial</label>
                    <div class="input-group">
                        <input
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.password }"
                            required
                        />
                        <button type="button" class="btn btn-outline-secondary" @click="showPassword = !showPassword">
                            <i :class="showPassword ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                        </button>
                        <div v-if="form.errors.password" class="invalid-feedback">{{ form.errors.password }}</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">
                        Enregistrer le professionnel
                    </button>
                </div>
            </form>
        </StaticModal>

        <!-- Static Modal : Modifier un professionnel -->
        <StaticModal id="modalEditProfessional" title="Modifier le professionnel" size="modal-lg">
            <form @submit.prevent="updateProfessional">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Nom complet</label>
                        <input v-model="editForm.name" type="text" class="form-control" required />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Adresse e-mail</label>
                        <input v-model="editForm.email" type="email" class="form-control" required />
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Téléphone</label>
                        <input v-model="editForm.phone" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Rôle métier</label>
                        <select v-model="editForm.role" class="form-select">
                            <option v-for="r in availableRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Agence</label>
                        <select v-model="editForm.agency_id" class="form-select" required>
                            <option v-for="a in agencies" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Sexe</label>
                        <select v-model="editForm.gender" class="form-select">
                            <option value="M">Masculin</option>
                            <option value="F">Féminin</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Statut matrimonial</label>
                        <select v-model="editForm.marital_status" class="form-select">
                            <option value="celibataire">Célibataire</option>
                            <option value="marie">Marié(e)</option>
                            <option value="divorce">Divorcé(e)</option>
                            <option value="veuf">Veuf / Veuve</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Date naissance</label>
                        <DatePickerInput v-model="editForm.birth_date" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Lieu naissance</label>
                        <input v-model="editForm.birth_place" type="text" class="form-control" />
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small">Salaire (FCFA)</label>
                        <input v-model.number="editForm.base_salary" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Commission (%)</label>
                        <input v-model="editForm.commission_rate" type="number" step="0.5" min="0" max="100" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Nouvelle photo (facultatif)</label>
                        <FileDropzone v-model="editForm.photo" label="Glisser-déposer une photo" />
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="editForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
