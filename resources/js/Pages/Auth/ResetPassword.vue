<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LanguageSwitcher from '../../Components/LanguageSwitcher.vue';

defineProps({
    garageName: String,
    maskedEmail: String,
});

const page = usePage();
const siteLogoUrl = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const form = useForm({
    password: '',
    password_confirmation: '',
});

const passwordRules = computed(() => {
    const pwd = form.password || '';
    return {
        length: pwd.length >= 8,
        upper: /[A-Z]/.test(pwd),
        lower: /[a-z]/.test(pwd),
        digit: /[0-9]/.test(pwd),
        special: /[_\-@!#$%^&*()+=\[\]{};:,.<>?\/]/.test(pwd),
    };
});

const passwordScore = computed(() => Object.values(passwordRules.value).filter(Boolean).length);

const passwordStrengthMeta = computed(() => {
    const score = passwordScore.value;
    if (score <= 1) return { label: 'Faible', barClass: 'bg-danger', textClass: 'text-danger', percent: 20 };
    if (score <= 3) return { label: 'Moyen', barClass: 'bg-warning', textClass: 'text-warning', percent: 60 };
    if (score === 4) return { label: 'Bon', barClass: 'bg-info', textClass: 'text-info', percent: 80 };
    return { label: 'Excellent', barClass: 'bg-success', textClass: 'text-success', percent: 100 };
});

function submit() {
    form.post('/mot-de-passe-oublie/nouveau-mot-de-passe');
}
</script>

<template>
    <Head title="Nouveau mot de passe" />
    <div class="min-vh-100 bg-light d-flex flex-column justify-content-center py-4 px-3">
        <div class="container" style="max-width: 500px;">
            <div class="d-flex justify-content-end mb-3">
                <LanguageSwitcher />
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img
                            :src="siteLogoUrl"
                            alt="MboaCar"
                            class="mb-3 rounded-3 p-1 bg-white border shadow-sm"
                            style="height: 38px; width: auto; object-fit: contain;"
                        />
                        <h1 class="h4 fw-bold mb-1">Choisir un nouveau mot de passe</h1>
                        <p class="text-muted small mb-0">
                            Compte propriétaire <strong>{{ garageName }}</strong> ({{ maskedEmail }})
                        </p>
                    </div>

                    <form @submit.prevent="submit">
                        <div class="mb-3">
                            <label for="newPassword" class="form-label small fw-semibold">Nouveau mot de passe</label>
                            <div class="input-group">
                                <input
                                    id="newPassword"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.password }"
                                    autocomplete="new-password"
                                    required
                                    autofocus
                                />
                                <button type="button" class="btn btn-outline-secondary" @click="showPassword = !showPassword">
                                    <i :class="showPassword ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                                </button>
                                <div v-if="form.errors.password" class="invalid-feedback">{{ form.errors.password }}</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="newPasswordConfirm" class="form-label small fw-semibold">Confirmer le mot de passe</label>
                            <div class="input-group">
                                <input
                                    id="newPasswordConfirm"
                                    v-model="form.password_confirmation"
                                    :type="showPasswordConfirmation ? 'text' : 'password'"
                                    class="form-control"
                                    autocomplete="new-password"
                                    required
                                />
                                <button type="button" class="btn btn-outline-secondary" @click="showPasswordConfirmation = !showPasswordConfirmation">
                                    <i :class="showPasswordConfirmation ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                                </button>
                            </div>
                        </div>

                        <div class="p-3 rounded-3 bg-light border mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold text-dark">Sécurité du mot de passe</span>
                                <span v-if="form.password.length > 0" class="small fw-bold" :class="passwordStrengthMeta.textClass">
                                    {{ passwordStrengthMeta.label }}
                                </span>
                            </div>
                            <div class="progress mb-2" style="height: 6px;">
                                <div
                                    class="progress-bar"
                                    :class="form.password.length > 0 ? passwordStrengthMeta.barClass : 'bg-secondary'"
                                    :style="{ width: (form.password.length > 0 ? passwordStrengthMeta.percent : 0) + '%', transition: 'width 0.25s ease' }"
                                ></div>
                            </div>
                            <div class="row g-1" style="font-size: 0.78rem;">
                                <div class="col-6" :class="passwordRules.length ? 'text-success fw-semibold' : 'text-muted'">
                                    <i class="bi me-1" :class="passwordRules.length ? 'bi-check-circle-fill' : 'bi-circle'"></i>8 caractères min.
                                </div>
                                <div class="col-6" :class="passwordRules.upper ? 'text-success fw-semibold' : 'text-muted'">
                                    <i class="bi me-1" :class="passwordRules.upper ? 'bi-check-circle-fill' : 'bi-circle'"></i>1 majuscule
                                </div>
                                <div class="col-6" :class="passwordRules.lower ? 'text-success fw-semibold' : 'text-muted'">
                                    <i class="bi me-1" :class="passwordRules.lower ? 'bi-check-circle-fill' : 'bi-circle'"></i>1 minuscule
                                </div>
                                <div class="col-6" :class="passwordRules.digit ? 'text-success fw-semibold' : 'text-muted'">
                                    <i class="bi me-1" :class="passwordRules.digit ? 'bi-check-circle-fill' : 'bi-circle'"></i>1 chiffre
                                </div>
                                <div class="col-12" :class="passwordRules.special ? 'text-success fw-semibold' : 'text-muted'">
                                    <i class="bi me-1" :class="passwordRules.special ? 'bi-check-circle-fill' : 'bi-circle'"></i>1 caractère spécial (_ - @ ! ...)
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" :disabled="form.processing || passwordScore < 5">
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2"></span>
                            {{ form.processing ? 'Enregistrement...' : 'Enregistrer mon nouveau mot de passe' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
