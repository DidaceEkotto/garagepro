<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    profile: Object,
});

const showCurrent = ref(false);
const showNew = ref(false);
const showConfirm = ref(false);

const infoForm = useForm({
    _method: 'PUT',
    name: props.profile?.name ?? '',
    email: props.profile?.email ?? '',
    phone: props.profile?.phone ?? '',
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
                            <div class="mb-3">
                                <label class="form-label small">Nom complet</label>
                                <input v-model="infoForm.name" type="text" class="form-control" required />
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Adresse e-mail</label>
                                <input v-model="infoForm.email" type="email" class="form-control" required />
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Téléphone</label>
                                <input v-model="infoForm.phone" type="text" class="form-control" />
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
