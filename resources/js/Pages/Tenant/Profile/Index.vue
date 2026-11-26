<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import PhoneInput from '@/Components/PhoneInput.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useLightbox } from '@/composables/useLightbox';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { openLightbox } = useLightbox();

const props = defineProps({
    profile: Object,
    agencies: { type: Array, default: () => [] },
});

const showCurrent = ref(false);
const showNew = ref(false);
const showConfirm = ref(false);

const infoForm = useForm({
    _method: 'PUT',
    name: props.profile?.name ?? '',
    email: props.profile?.email ?? '',
    phone: props.profile?.phone ?? '',
    gender: props.profile?.gender ?? '',
    default_agency_id: props.profile?.default_agency_id ?? '',
    photo: null,
});

const passForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function saveInfo() {
    infoForm.post('/mon-profil', { forceFormData: true });
}

function savePassword() {
    passForm.put('/mon-profil/mot-de-passe', {
        onSuccess: () => passForm.reset(),
    });
}
</script>

<template>
    <Head title="Mon Profil" />
    <TenantLayout>
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-0">Mon Profil &amp; Sécurité</h1>
            <p class="text-muted small mb-0">Modifiez vos informations personnelles, votre photo et votre mot de passe</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3">Informations personnelles</div>
                    <div class="card-body p-4">
                        <form @submit.prevent="saveInfo">
                            <div v-if="profile?.photo_url || profile?.roles?.length" class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
                                <img
                                    v-if="profile?.photo_url"
                                    :src="profile.photo_url"
                                    alt="Photo de profil"
                                    class="rounded-circle border"
                                    style="width: 56px; height: 56px; object-fit: cover; cursor: zoom-in;"
                                    title="Cliquer pour agrandir"
                                    @click="openLightbox([profile.photo_url], 0, profile?.name || 'Photo de profil')"
                                />
                                <div class="small">
                                    <div class="d-flex flex-wrap gap-1 mb-1">
                                        <span v-for="r in (profile?.roles || [])" :key="r" class="badge bg-primary-subtle text-primary border border-primary-subtle">{{ r }}</span>
                                        <span class="badge" :class="profile?.is_active ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'">
                                            {{ profile?.is_active ? 'Compte actif' : 'Désactivé' }}
                                        </span>
                                    </div>
                                    <div v-if="profile?.email_verified_at" class="text-muted">E-mail vérifié le {{ profile.email_verified_at }}</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small">Nom complet <span class="text-danger">*</span></label>
                                <input v-model="infoForm.name" type="text" class="form-control" required />
                                <div v-if="infoForm.errors.name" class="text-danger small mt-1">{{ infoForm.errors.name }}</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Adresse e-mail <span class="text-danger">*</span></label>
                                <input v-model="infoForm.email" type="email" class="form-control" required />
                                <div v-if="infoForm.errors.email" class="text-danger small mt-1">{{ infoForm.errors.email }}</div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small">Téléphone / WhatsApp</label>
                                    <PhoneInput v-model="infoForm.phone" :is-invalid="!!infoForm.errors.phone" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Sexe</label>
                                    <select v-model="infoForm.gender" class="form-select">
                                        <option value="">-- Non précisé --</option>
                                        <option value="homme">Homme</option>
                                        <option value="femme">Femme</option>
                                        <option value="autre">Autre</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Agence par défaut</label>
                                <select v-model="infoForm.default_agency_id" class="form-select">
                                    <option value="">-- Aucune --</option>
                                    <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small">Photo de profil (facultatif)</label>
                                <FileDropzone v-model="infoForm.photo" label="Glissez-déposez votre photo de profil ici" />
                            </div>
                            <button type="submit" class="btn btn-primary px-4" :disabled="infoForm.processing">
                                Enregistrer mes informations
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3">Modifier mon mot de passe</div>
                    <div class="card-body p-4">
                        <form @submit.prevent="savePassword">
                            <div class="mb-3">
                                <label class="form-label small">Mot de passe actuel</label>
                                <div class="input-group">
                                    <input v-model="passForm.current_password" :type="showCurrent ? 'text' : 'password'" class="form-control" :class="{ 'is-invalid': passForm.errors.current_password }" required />
                                    <button type="button" class="btn btn-outline-secondary" @click="showCurrent = !showCurrent"><i :class="showCurrent ? 'bi bi-eye-slash' : 'bi bi-eye'"></i></button>
                                    <div v-if="passForm.errors.current_password" class="invalid-feedback">{{ passForm.errors.current_password }}</div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Nouveau mot de passe</label>
                                <div class="input-group">
                                    <input v-model="passForm.password" :type="showNew ? 'text' : 'password'" class="form-control" :class="{ 'is-invalid': passForm.errors.password }" required />
                                    <button type="button" class="btn btn-outline-secondary" @click="showNew = !showNew"><i :class="showNew ? 'bi bi-eye-slash' : 'bi bi-eye'"></i></button>
                                    <div v-if="passForm.errors.password" class="invalid-feedback">{{ passForm.errors.password }}</div>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small">Confirmer le nouveau mot de passe</label>
                                <div class="input-group">
                                    <input v-model="passForm.password_confirmation" :type="showConfirm ? 'text' : 'password'" class="form-control" required />
                                    <button type="button" class="btn btn-outline-secondary" @click="showConfirm = !showConfirm"><i :class="showConfirm ? 'bi bi-eye-slash' : 'bi bi-eye'"></i></button>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-dark px-4" :disabled="passForm.processing">
                                Mettre à jour le mot de passe
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
