<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import LanguageSwitcher from '../../Components/LanguageSwitcher.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    tenantName: {
        type: String,
        default: null,
    },
    blockedUntilTimestamp: {
        type: Number,
        default: null,
    },
});

const { t } = useI18n();
const page = usePage();
const siteLogoUrl = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');
const showPassword = ref(false);
const clientValidationError = ref('');
const nowSec = ref(Math.floor(Date.now() / 1000));
let timerId = null;

onMounted(() => {
    timerId = setInterval(() => {
        nowSec.value = Math.floor(Date.now() / 1000);
    }, 1000);
});

onBeforeUnmount(() => {
    if (timerId) clearInterval(timerId);
});

const remainingSeconds = computed(() => {
    if (!props.blockedUntilTimestamp) return 0;
    return Math.max(0, props.blockedUntilTimestamp - nowSec.value);
});

const isBlocked = computed(() => remainingSeconds.value > 0);

const countdownFormatted = computed(() => {
    const mins = Math.floor(remainingSeconds.value / 60);
    const secs = remainingSeconds.value % 60;
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

watch(
    () => props.blockedUntilTimestamp,
    () => {
        nowSec.value = Math.floor(Date.now() / 1000);
    }
);

function submit() {
    clientValidationError.value = '';
    if (isBlocked.value) return;

    const emailTrimmed = form.email.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailTrimmed)) {
        clientValidationError.value = 'Veuillez saisir une adresse e-mail valide.';
        return;
    }
    if (!form.password || form.password.length < 1) {
        clientValidationError.value = 'Veuillez saisir votre mot de passe.';
        return;
    }

    form.email = emailTrimmed;
    form.post('/connexion', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head :title="t('auth.loginTitle')" />
    <div class="container-fluid p-0 min-vh-100 d-flex">
        <div class="row g-0 flex-grow-1 w-100">
            <!-- Partie 1 : Photo + Dégradé + Texte (en 2e sur mobile, à gauche sur desktop) -->
            <div
                class="col-lg-6 order-2 order-lg-1 text-white d-flex align-items-center p-4 p-lg-5"
                style="min-height: 360px; background: linear-gradient(135deg, rgba(15, 23, 42, 0.90), rgba(37, 99, 235, 0.78)), url('/images/auth-garage.jpg') center/cover no-repeat;"
            >
                <div class="mx-auto hero-animate" style="max-width: 480px;">
                    <div class="mb-3">
                        <img :src="siteLogoUrl" alt="MboaCar" class="bg-white rounded-3 px-2 py-1 shadow-sm" style="height: 38px; width: auto; object-fit: contain;" />
                    </div>
                    <h2 class="h2 fw-bold mb-3">{{ t('auth.loginHeroTitle') }}</h2>
                    <p class="text-white-50 mb-4">{{ t('auth.loginHeroSub') }}</p>
                    <ul class="list-unstyled vstack gap-2 small">
                        <li><i class="bi bi-check-circle-fill text-info me-2"></i>{{ t('features.f1Title') }}</li>
                        <li><i class="bi bi-check-circle-fill text-info me-2"></i>{{ t('features.f4Title') }}</li>
                        <li><i class="bi bi-check-circle-fill text-info me-2"></i>{{ t('features.f2Title') }}</li>
                    </ul>
                </div>
            </div>

            <!-- Partie 2 : Formulaire (en 1er sur mobile, à droite sur desktop) -->
            <div class="col-lg-6 order-1 order-lg-2 bg-white d-flex align-items-center justify-content-center p-4 p-lg-5">
                <div class="w-100 hero-animate" style="max-width: 420px;">
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <Link href="/" class="text-decoration-none small text-muted">
                            <i class="bi bi-arrow-left me-1"></i>{{ t('auth.backHome') }}
                        </Link>
                        <LanguageSwitcher />
                    </div>

                    <h1 class="h3 fw-bold text-primary mb-1">
                        <i class="bi bi-wrench-adjustable-circle me-2"></i>{{ tenantName ? tenantName : t('auth.loginTitle') }}
                    </h1>
                    <p class="text-muted small mb-4">{{ t('auth.loginSub') }}</p>

                    <!-- Compte à rebours de blocage IP (10 minutes après 5 échecs) -->
                    <div v-if="isBlocked" class="alert alert-danger border-0 shadow-sm mb-4">
                        <div class="d-flex align-items-center gap-2 fw-bold mb-1">
                            <i class="bi bi-shield-lock-fill fs-5"></i>
                            <span>Connexion temporairement bloquée</span>
                        </div>
                        <p class="small mb-2">Suite à 5 tentatives échouées, cette adresse IP est bloquée pendant 10 minutes.</p>
                        <div class="text-center py-2 px-3 bg-white text-danger rounded-3 border border-danger fw-bold fs-4 font-monospace">
                            <i class="bi bi-hourglass-split me-2"></i>{{ countdownFormatted }}
                        </div>
                    </div>

                    <div v-if="page.props.flash?.success" class="alert alert-success small py-2 mb-3 border-0">
                        <i class="bi bi-check-circle-fill me-1"></i>{{ page.props.flash.success }}
                    </div>

                    <div v-if="clientValidationError" class="alert alert-warning small py-2 mb-3">
                        {{ clientValidationError }}
                    </div>

                    <form @submit.prevent="submit">
                        <div class="mb-3">
                            <label for="email" class="form-label small">{{ t('auth.email') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.email }"
                                    :disabled="isBlocked"
                                    required
                                    autofocus
                                />
                                <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label small">{{ t('auth.password') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input
                                    id="password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.password }"
                                    :disabled="isBlocked"
                                    required
                                />
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :disabled="isBlocked"
                                    @click="showPassword = !showPassword"
                                >
                                    <i :class="showPassword ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                                </button>
                                <div v-if="form.errors.password" class="invalid-feedback">{{ form.errors.password }}</div>
                            </div>
                        </div>

                        <div class="mb-4 d-flex justify-content-between align-items-center">
                            <div class="form-check mb-0">
                                <input id="remember" v-model="form.remember" type="checkbox" class="form-check-input" :disabled="isBlocked" />
                                <label for="remember" class="form-check-label small">{{ t('auth.rememberMe') }}</label>
                            </div>
                            <Link href="/mot-de-passe-oublie" class="small text-decoration-none fw-semibold text-primary">
                                {{ t('layoutExtra.forgotPassword') }}
                            </Link>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-3" :disabled="form.processing || isBlocked">
                            {{ form.processing ? t('auth.loggingIn') : t('auth.loginBtn') }}
                        </button>

                        <div class="text-center small text-muted">
                            {{ t('auth.noAccount') }}
                            <Link href="/inscription" class="text-decoration-none fw-semibold">{{ t('auth.createAccountLink') }}</Link>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
