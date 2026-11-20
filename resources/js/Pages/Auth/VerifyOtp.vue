<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import LanguageSwitcher from '../../Components/LanguageSwitcher.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    phone: String,
    email: String,
    garageName: String,
    channel: { type: String, default: 'whatsapp' },
    expiresAt: Number,
    resendAvailableAt: Number,
    attempts: { type: Number, default: 0 },
    maxAttempts: { type: Number, default: 3 },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashWarning = computed(() => page.props.flash?.warning);

const form = useForm({
    code: '',
});

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

const expiresLeft = computed(() => Math.max(0, (props.expiresAt ?? 0) - nowSec.value));
const resendLeft = computed(() => Math.max(0, (props.resendAvailableAt ?? 0) - nowSec.value));

function formatMmSs(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function submitCode() {
    form.post('/inscription/verification-otp');
}

function resendCode() {
    if (resendLeft.value > 0) return;
    router.post('/inscription/verification-otp/renvoyer', {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('otp.title')" />
    <div class="min-vh-100 bg-light d-flex flex-column justify-content-center py-4 px-3">
        <div class="container" style="max-width: 480px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <Link href="/inscription" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i>{{ t('layoutExtra.cancel') }}
                </Link>
                <LanguageSwitcher />
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <span class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                            :class="channel === 'email' ? 'bg-primary bg-opacity-10 text-primary' : 'bg-success bg-opacity-10 text-success'"
                            style="width: 56px; height: 56px;">
                            <i class="fs-3 bi" :class="channel === 'email' ? 'bi-envelope-check' : 'bi-whatsapp'"></i>
                        </span>
                        <h1 class="h4 fw-bold mb-1">{{ t('otp.title') }}</h1>
                        <p class="text-muted small mb-1">
                            {{ channel === 'email' ? t('otp.subtitleEmail') : t('otp.subtitleWhatsapp') }}
                        </p>
                        <div class="fw-bold text-dark">
                            {{ channel === 'email' ? email : phone }}
                        </div>
                    </div>

                    <div v-if="flashSuccess" class="alert alert-success small border-0 mb-3">{{ flashSuccess }}</div>
                    <div v-if="flashWarning" class="alert alert-warning small border-0 mb-3">{{ flashWarning }}</div>

                    <div class="alert alert-info small border-0 mb-3">
                        <i class="bi bi-clock me-1"></i>{{ t('otp.validityNotice') }}
                        <div class="fw-bold mt-1">
                            {{ expiresLeft > 0 ? t('otp.expiresIn', { time: formatMmSs(expiresLeft) }) : t('otp.expired') }}
                        </div>
                    </div>

                    <form @submit.prevent="submitCode">
                        <div class="mb-3">
                            <label for="otpCode" class="form-label small fw-semibold">{{ t('otp.codeLabel') }}</label>
                            <input
                                id="otpCode"
                                v-model="form.code"
                                type="text"
                                maxlength="5"
                                class="form-control form-control-lg text-center fw-bold text-uppercase"
                                style="letter-spacing: 0.45em;"
                                :class="{ 'is-invalid': form.errors.code }"
                                placeholder="XXXXX"
                                autocomplete="one-time-code"
                                required
                            />
                            <div v-if="form.errors.code" class="invalid-feedback">{{ form.errors.code }}</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-3" :disabled="form.processing || expiresLeft === 0">
                            {{ form.processing ? t('otp.verifying') : t('otp.verifyBtn') }}
                        </button>
                    </form>

                    <div class="text-center">
                        <button
                            type="button"
                            class="btn btn-outline-primary btn-sm w-100"
                            :disabled="resendLeft > 0"
                            @click="resendCode"
                        >
                            <i class="bi bi-arrow-clockwise me-1"></i>
                            <span v-if="resendLeft > 0">{{ t('otp.resendWait', { time: formatMmSs(resendLeft) }) }}</span>
                            <span v-else>{{ t('otp.resendBtn') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
