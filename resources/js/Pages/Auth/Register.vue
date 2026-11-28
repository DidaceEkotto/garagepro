<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import LanguageSwitcher from '../../Components/LanguageSwitcher.vue';
import LocationAutocomplete from '../../Components/LocationAutocomplete.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    baseDomain: { type: String, default: 'mboacar.com' },
    trialDays: { type: Number, default: 7 },
    monthlyPrice: { type: Number, default: 10000 },
    countries: { type: Array, default: () => [] },
    promoCode: { type: String, default: '' },
    referralDiscount: { type: Number, default: 5000 },
});

const { t } = useI18n();
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const form = useForm({
    type: 'garage',
    garage_name: '',
    slug: '',
    owner_last_name: '',
    owner_first_name: '',
    gender: 'homme',
    email: '',
    phone: '',
    country: 'CM',
    city: '',
    formatted_location: '',
    latitude: null,
    longitude: null,
    agencies_count: 1,
    promo_code: props.promoCode || '',
    password: '',
    password_confirmation: '',
});

const selectedDialCode = computed(() => {
    const found = props.countries.find((c) => c.code === form.country);
    return found ? found.dial_code : '+237';
});

const passwordRules = computed(() => {
    const pwd = form.password || '';
    return {
        length: pwd.length >= 8,
        upper: /[A-Z]/.test(pwd),
        digit: /[0-9]/.test(pwd),
        special: /[_\-@]/.test(pwd),
    };
});

const passwordScore = computed(() => {
    const r = passwordRules.value;
    return [r.length, r.upper, r.digit, r.special].filter(Boolean).length;
});

const passwordStrengthMeta = computed(() => {
    const score = passwordScore.value;
    if (score <= 1) return { percent: 25, barClass: 'bg-danger', textClass: 'text-danger', label: t('auth.pwdWeak') };
    if (score === 2) return { percent: 50, barClass: 'bg-warning', textClass: 'text-warning', label: t('auth.pwdMedium') };
    if (score === 3) return { percent: 75, barClass: 'bg-info', textClass: 'text-info', label: t('auth.pwdStrong') };
    return { percent: 100, barClass: 'bg-success', textClass: 'text-success', label: t('auth.pwdSecure') };
});

const slugManuallyEdited = ref(false);

watch(
    () => form.garage_name,
    (name) => {
        if (!slugManuallyEdited.value) {
            form.slug = name
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    }
);

function onSlugInput() {
    slugManuallyEdited.value = form.slug.trim() !== '';
}

function onLocationSelected(loc) {
    form.city = loc.city || form.city;
    form.formatted_location = loc.formatted_location || loc.city;
    form.latitude = loc.latitude;
    form.longitude = loc.longitude;
}

function submit() {
    form.post('/inscription', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t('auth.registerTitle')" />

    <div class="container-fluid p-0 min-vh-100 d-flex">
        <div class="row g-0 flex-grow-1 w-100">
            <!-- Partie 1 : Photo + Dégradé + Texte (en 2e sur mobile, à gauche sur desktop) -->
            <div
                class="col-lg-6 order-2 order-lg-1 text-white p-0"
                style="min-height: 360px; background: linear-gradient(135deg, rgba(15, 23, 42, 0.90), rgba(37, 99, 235, 0.78)), url('/images/auth-garage.jpg') center/cover no-repeat;"
            >
                <div class="sticky-lg-top d-flex align-items-center justify-content-center p-4 p-lg-5" style="min-height: 100vh; top: 0;">
                    <div class="mx-auto hero-animate" style="max-width: 480px;">
                    <span class="badge bg-warning text-dark mb-3 px-3 py-2 fw-semibold">
                        {{ t('auth.benefit1', { days: trialDays }) }}
                    </span>
                    <h2 class="h2 fw-bold mb-3">{{ t('auth.registerHeroTitle') }}</h2>
                    <p class="text-white-50 mb-4">{{ t('auth.registerHeroSub') }}</p>
                    <ul class="list-unstyled vstack gap-3 small">
                        <li><i class="bi bi-check-circle-fill text-info me-2"></i>{{ t('auth.benefit1', { days: trialDays }) }}</li>
                        <li><i class="bi bi-check-circle-fill text-info me-2"></i>{{ t('auth.benefit2') }}</li>
                        <li><i class="bi bi-check-circle-fill text-info me-2"></i>{{ t('auth.benefit3') }} ({{ new Intl.NumberFormat('fr-FR').format(monthlyPrice) }} FCFA / mois)</li>
                    </ul>
                    </div>
                </div>
            </div>

            <!-- Partie 2 : Formulaire (en 1er sur mobile, à droite sur desktop) -->
            <div class="col-lg-6 order-1 order-lg-2 bg-white d-flex align-items-center justify-content-center p-4 p-lg-5">
                <div class="w-100 hero-animate" style="max-width: 500px;">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <Link href="/" class="text-decoration-none small text-muted">
                            <i class="bi bi-arrow-left me-1"></i>{{ t('auth.backHome') }}
                        </Link>
                        <LanguageSwitcher />
                    </div>

                    <h1 class="h3 fw-bold text-primary mb-1">{{ t('auth.registerTitle') }}</h1>
                    <p class="text-muted small mb-4">{{ t('auth.registerSub') }}</p>

                    <form @submit.prevent="submit">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">{{ t('layoutExtra.establishmentType') }} <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <div class="col-4">
                                    <button
                                        type="button"
                                        class="btn w-100 text-start p-2 small border"
                                        :class="form.type === 'garage' ? 'btn-primary text-white border-primary' : 'btn-light'"
                                        @click="form.type = 'garage'"
                                    >
                                        <i class="bi bi-wrench-adjustable d-block fs-5 mb-1"></i>
                                        <span class="fw-bold d-block">{{ t('layoutExtra.typeGarage') }}</span>
                                        <span class="opacity-75" style="font-size: 0.7rem;">{{ t('layoutExtra.typeGarageSub') }}</span>
                                    </button>
                                </div>
                                <div class="col-4">
                                    <button
                                        type="button"
                                        class="btn w-100 text-start p-2 small border"
                                        :class="form.type === 'centre_technique' ? 'btn-primary text-white border-primary' : 'btn-light'"
                                        @click="form.type = 'centre_technique'"
                                    >
                                        <i class="bi bi-clipboard2-check d-block fs-5 mb-1"></i>
                                        <span class="fw-bold d-block">{{ t('layoutExtra.typeTech') }}</span>
                                        <span class="opacity-75" style="font-size: 0.7rem;">{{ t('layoutExtra.typeTechSub') }}</span>
                                    </button>
                                </div>
                                <div class="col-4">
                                    <button
                                        type="button"
                                        class="btn w-100 text-start p-2 small border"
                                        :class="form.type === 'assurance' ? 'btn-primary text-white border-primary' : 'btn-light'"
                                        @click="form.type = 'assurance'"
                                    >
                                        <i class="bi bi-shield-check d-block fs-5 mb-1"></i>
                                        <span class="fw-bold d-block">{{ t('layoutExtra.typeInsurance') }}</span>
                                        <span class="opacity-75" style="font-size: 0.7rem;">{{ t('layoutExtra.typeInsuranceSub') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="garage_name" class="form-label small">
                                {{ form.type === 'centre_technique' ? 'Nom du Centre Technique' : (form.type === 'assurance' ? 'Nom de l\'Assurance / Cabinet' : t('auth.garageName')) }}
                                <span class="text-danger">*</span>
                            </label>
                            <input
                                id="garage_name"
                                v-model="form.garage_name"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.garage_name }"
                                placeholder="Ex: Garage Excellence Douala"
                                required
                            />
                            <div v-if="form.errors.garage_name" class="invalid-feedback">{{ form.errors.garage_name }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="slug" class="form-label small">{{ t('auth.slug') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input
                                    id="slug"
                                    v-model="form.slug"
                                    type="text"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.slug }"
                                    placeholder="garage-excellence"
                                    required
                                    @input="onSlugInput"
                                />
                                <span class="input-group-text">.{{ baseDomain }}</span>
                            </div>
                            <div v-if="form.errors.slug" class="text-danger small mt-1">{{ form.errors.slug }}</div>
                            <div class="form-text text-warning-emphasis mt-1" style="font-size: 0.78rem;">
                                <i class="bi bi-lock-fill me-1"></i>Attention : une fois votre compte créé, ce sous-domaine est définitif et ne pourra plus être modifié, même dans les paramètres.
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label for="owner_last_name" class="form-label small">Nom du propriétaire <span class="text-danger">*</span></label>
                                <input
                                    id="owner_last_name"
                                    v-model="form.owner_last_name"
                                    type="text"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.owner_last_name }"
                                    placeholder="Ex: Kouam"
                                    required
                                />
                                <div v-if="form.errors.owner_last_name" class="invalid-feedback">{{ form.errors.owner_last_name }}</div>
                            </div>
                            <div class="col-md-4">
                                <label for="owner_first_name" class="form-label small">Prénom <span class="text-danger">*</span></label>
                                <input
                                    id="owner_first_name"
                                    v-model="form.owner_first_name"
                                    type="text"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.owner_first_name }"
                                    placeholder="Ex: Jean"
                                    required
                                />
                                <div v-if="form.errors.owner_first_name" class="invalid-feedback">{{ form.errors.owner_first_name }}</div>
                            </div>
                            <div class="col-md-4">
                                <label for="gender" class="form-label small">Sexe <span class="text-danger">*</span></label>
                                <select
                                    id="gender"
                                    v-model="form.gender"
                                    class="form-select"
                                    :class="{ 'is-invalid': form.errors.gender }"
                                    required
                                >
                                    <option value="homme">Homme</option>
                                    <option value="femme">Femme</option>
                                    <option value="autre">Autre</option>
                                </select>
                                <div v-if="form.errors.gender" class="invalid-feedback">{{ form.errors.gender }}</div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-7">
                                <label for="email" class="form-label small">{{ t('auth.email') }} <span class="text-danger">*</span></label>
                                <input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.email }"
                                    required
                                />
                                <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                            </div>
                            <div class="col-md-5">
                                <label for="agencies_count" class="form-label small">{{ t('auth.agenciesCount') }} <span class="text-danger">*</span></label>
                                <input
                                    id="agencies_count"
                                    v-model.number="form.agencies_count"
                                    type="number"
                                    min="1"
                                    max="20"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.agencies_count }"
                                    required
                                />
                                <div v-if="form.errors.agencies_count" class="invalid-feedback">{{ form.errors.agencies_count }}</div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label for="country" class="form-label small">{{ t('auth.country') }} <span class="text-danger">*</span></label>
                                <select
                                    id="country"
                                    v-model="form.country"
                                    class="form-select"
                                    :class="{ 'is-invalid': form.errors.country }"
                                    required
                                >
                                    <option value="" disabled>{{ t('auth.selectCountry') }}</option>
                                    <option v-for="c in countries" :key="c.code" :value="c.code">
                                        {{ c.name }} ({{ c.dial_code }})
                                    </option>
                                </select>
                                <div v-if="form.errors.country" class="invalid-feedback">{{ form.errors.country }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Localisation (OpenStreetMap) <span class="text-danger">*</span></label>
                                <LocationAutocomplete
                                    v-model="form.city"
                                    :country="form.country"
                                    :required="true"
                                    placeholder="Quartier, rue ou ville..."
                                    @select="onLocationSelected"
                                />
                                <div v-if="form.latitude && form.longitude" class="form-text text-success small">
                                    <i class="bi bi-check-circle-fill me-1"></i>GPS enregistré ({{ Number(form.latitude).toFixed(4) }}, {{ Number(form.longitude).toFixed(4) }})
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="phone" class="form-label small fw-semibold">{{ t('otp.whatsappLabel') }} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text fw-semibold bg-light text-success user-select-none">
                                    <i class="bi bi-whatsapp me-1"></i>{{ selectedDialCode }}
                                </span>
                                <input
                                    id="phone"
                                    v-model="form.phone"
                                    type="tel"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.phone }"
                                    placeholder="6XX XXX XXX"
                                    required
                                />
                                <div v-if="form.errors.phone" class="invalid-feedback">{{ form.errors.phone }}</div>
                            </div>
                            <div class="form-text small">{{ t('otp.whatsappHint') }}</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">{{ t('aff.promoOptional') }}</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white"><i class="bi bi-ticket-perforated text-primary"></i></span>
                                <input
                                    v-model="form.promo_code"
                                    type="text"
                                    class="form-control text-uppercase"
                                    :class="{ 'is-invalid': form.errors.promo_code }"
                                    placeholder="Ex: GARAGE-XXXX"
                                    maxlength="32"
                                />
                            </div>
                            <div v-if="referralDiscount > 0" class="form-text text-success fw-semibold mt-1">
                                <i class="bi bi-gift me-1"></i>Avantage parrainage : bénéficiez de -{{ new Intl.NumberFormat('fr-FR').format(referralDiscount) }} FCFA sur votre 1<sup>re</sup> facture d'abonnement avec un code valide.
                            </div>
                            <div v-if="form.errors.promo_code" class="text-danger small mt-1">{{ form.errors.promo_code }}</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-3" :disabled="form.processing">
                            {{ form.processing ? t('auth.creating') : t('otp.continueBtn') }}
                        </button>

                        <div class="text-center small text-muted">
                            {{ t('auth.alreadyAccount') }}
                            <Link href="/connexion" class="text-decoration-none fw-semibold">{{ t('auth.loginLink') }}</Link>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
