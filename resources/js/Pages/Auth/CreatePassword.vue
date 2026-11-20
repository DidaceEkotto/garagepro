<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LanguageSwitcher from '../../Components/LanguageSwitcher.vue';
import { useI18n } from '../../composables/useI18n';

defineProps({
    garageName: String,
    email: String,
    phone: String,
});

const { t } = useI18n();
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
        special: /[_\-@]/.test(pwd),
    };
});

const passwordScore = computed(() => Object.values(passwordRules.value).filter(Boolean).length);

const passwordStrengthMeta = computed(() => {
    const score = passwordScore.value;
    if (score <= 1) return { label: t('auth.pwdWeak'), barClass: 'bg-danger', textClass: 'text-danger', percent: 20 };
    if (score <= 3) return { label: t('auth.pwdMedium'), barClass: 'bg-warning', textClass: 'text-warning', percent: 60 };
    if (score === 4) return { label: t('auth.pwdGood'), barClass: 'bg-info', textClass: 'text-info', percent: 80 };
    return { label: t('auth.pwdExcellent'), barClass: 'bg-success', textClass: 'text-success', percent: 100 };
});

function submit() {
    form.post('/inscription/mot-de-passe');
}
</script>

<template>
    <Head :title="t('otp.passwordTitle')" />
    <div class="min-vh-100 bg-light d-flex flex-column justify-content-center py-4 px-3">
        <div class="container" style="max-width: 500px;">
            <div class="d-flex justify-content-end mb-3">
                <LanguageSwitcher />
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <span class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                            <i class="bi bi-shield-lock fs-3"></i>
                        </span>
                        <h1 class="h4 fw-bold mb-1">{{ t('otp.passwordTitle') }}</h1>
                        <p class="text-muted small mb-0">{{ t('otp.passwordSubtitle') }} — <strong>{{ garageName }}</strong></p>
                    </div>

                    <form @submit.prevent="submit">
                        <div class="mb-3">
                            <label for="password" class="form-label small fw-semibold">{{ t('auth.password') }}</label>
                            <div class="input-group">
                                <input
                                    id="password"
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

                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label small fw-semibold">{{ t('auth.passwordConfirm') }}</label>
                            <div class="input-group">
                                <input
                                    id="password_confirmation"
                                    v-model="form.password_confirmation"
                                    :type="showPasswordConfirmation ? 'text' : 'password'"
                                    class="form-control"
                                    required
                                />
                                <button type="button" class="btn btn-outline-secondary" @click="showPasswordConfirmation = !showPasswordConfirmation">
                                    <i :class="showPasswordConfirmation ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                                </button>
                            </div>
                        </div>

                        <div class="p-3 rounded-3 bg-light border mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold text-dark">{{ t('auth.pwdStrength') }}</span>
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
                                    <i class="bi me-1" :class="passwordRules.length ? 'bi-check-circle-fill' : 'bi-circle'"></i>{{ t('auth.pwdRuleLen') }}
                                </div>
                                <div class="col-6" :class="passwordRules.upper ? 'text-success fw-semibold' : 'text-muted'">
                                    <i class="bi me-1" :class="passwordRules.upper ? 'bi-check-circle-fill' : 'bi-circle'"></i>{{ t('auth.pwdRuleUpper') }}
                                </div>
                                <div class="col-6" :class="passwordRules.lower ? 'text-success fw-semibold' : 'text-muted'">
                                    <i class="bi me-1" :class="passwordRules.lower ? 'bi-check-circle-fill' : 'bi-circle'"></i>{{ t('otp.pwdRuleLower') }}
                                </div>
                                <div class="col-6" :class="passwordRules.digit ? 'text-success fw-semibold' : 'text-muted'">
                                    <i class="bi me-1" :class="passwordRules.digit ? 'bi-check-circle-fill' : 'bi-circle'"></i>{{ t('auth.pwdRuleDigit') }}
                                </div>
                                <div class="col-12" :class="passwordRules.special ? 'text-success fw-semibold' : 'text-muted'">
                                    <i class="bi me-1" :class="passwordRules.special ? 'bi-check-circle-fill' : 'bi-circle'"></i>{{ t('auth.pwdRuleSpecial') }}
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" :disabled="form.processing">
                            {{ form.processing ? t('auth.creating') : t('otp.finishBtn') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
