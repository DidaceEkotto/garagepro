<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    authenticated: { type: Boolean, default: false },
    otpPendingPhone: { type: String, default: null },
    otpPendingDialCode: { type: String, default: '+237' },
    countries: { type: Array, default: () => [] },
    profile: { type: Object, default: null },
    garages: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    repairOrders: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const siteName = computed(() => page.props.app?.site?.name || 'MboaCar');
const siteLogo = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');

const activeTab = ref('vehicles');

const countryList = computed(() => {
    if (Array.isArray(props.countries) && props.countries.length > 0) {
        return props.countries;
    }
    return [
        { code: 'CM', name: 'Cameroun', dial_code: '+237' },
        { code: 'CI', name: "Côte d'Ivoire", dial_code: '+225' },
        { code: 'SN', name: 'Sénégal', dial_code: '+221' },
        { code: 'GA', name: 'Gabon', dial_code: '+241' },
        { code: 'CG', name: 'Congo (Brazzaville)', dial_code: '+242' },
        { code: 'CD', name: 'RD Congo', dial_code: '+243' },
        { code: 'BJ', name: 'Bénin', dial_code: '+229' },
        { code: 'TG', name: 'Togo', dial_code: '+228' },
        { code: 'BF', name: 'Burkina Faso', dial_code: '+226' },
        { code: 'ML', name: 'Mali', dial_code: '+223' },
        { code: 'TD', name: 'Tchad', dial_code: '+235' },
        { code: 'MA', name: 'Maroc', dial_code: '+212' },
    ];
});

function extractLocalPhone(fullPhone, dialCode) {
    if (!fullPhone) return '';
    const cleaned = String(fullPhone).trim();
    const dial = String(dialCode || '+237').trim();
    const dialDigits = dial.replace(/\D/g, '');
    if (cleaned.startsWith(dial)) {
        return cleaned.slice(dial.length).trim();
    }
    const digits = cleaned.replace(/\D/g, '');
    if (dialDigits && digits.startsWith(dialDigits) && digits.length > dialDigits.length + 5) {
        return digits.slice(dialDigits.length);
    }
    return cleaned.replace(/^\+/, '');
}

const selectedDialCode = ref(props.otpPendingDialCode || '+237');
const localPhone = ref(extractLocalPhone(props.otpPendingPhone, selectedDialCode.value));
const showOtpStep = ref(Boolean(props.otpPendingPhone));
const phoneInputRef = ref(null);

const fullPhonePreview = computed(() => {
    const digits = localPhone.value.replace(/\D/g, '');
    if (!digits) return '';
    return `${selectedDialCode.value}${digits}`;
});

const otpForm = useForm({
    phone_dial_code: selectedDialCode.value,
    phone: localPhone.value,
});

const verifyForm = useForm({
    phone_dial_code: selectedDialCode.value,
    phone: props.otpPendingPhone ?? '',
    code: '',
});

watch(selectedDialCode, (val) => {
    otpForm.phone_dial_code = val;
    verifyForm.phone_dial_code = val;
});

watch(
    () => props.otpPendingPhone,
    (val) => {
        if (val) {
            showOtpStep.value = true;
        }
    }
);

function onLocalPhoneInput(event) {
    let val = event.target.value;
    // Si l'utilisateur colle un numéro complet avec indicatif, on détecte automatiquement le pays
    if (val.trim().startsWith('+')) {
        const compact = val.replace(/\s+/g, '');
        const matched = [...countryList.value]
            .sort((a, b) => b.dial_code.length - a.dial_code.length)
            .find((c) => compact.startsWith(c.dial_code));
        if (matched) {
            selectedDialCode.value = matched.dial_code;
            val = compact.slice(matched.dial_code.length);
        }
    }
    localPhone.value = val;
    otpForm.phone = val;
}

function onOtpCodeInput(event) {
    verifyForm.code = event.target.value.replace(/\D/g, '').slice(0, 5);
}

function requestOtp() {
    const cleanLocal = localPhone.value.replace(/\D/g, '');
    otpForm.phone_dial_code = selectedDialCode.value;
    otpForm.phone = cleanLocal;

    otpForm.post('/mon-espace-client/otp', {
        preserveScroll: true,
        onSuccess: () => {
            showOtpStep.value = true;
            verifyForm.phone_dial_code = selectedDialCode.value;
            verifyForm.phone = `${selectedDialCode.value}${cleanLocal}`;
            verifyForm.code = '';
        },
    });
}

function verifyOtp() {
    const cleanLocal = localPhone.value.replace(/\D/g, '');
    verifyForm.phone_dial_code = selectedDialCode.value;
    verifyForm.phone = cleanLocal ? `${selectedDialCode.value}${cleanLocal}` : (props.otpPendingPhone || verifyForm.phone);
    verifyForm.post('/mon-espace-client/verifier', {
        preserveScroll: true,
    });
}

function resetPhoneStep() {
    showOtpStep.value = false;
    localPhone.value = '';
    otpForm.phone = '';
    otpForm.clearErrors();
    verifyForm.phone = '';
    verifyForm.code = '';
    verifyForm.clearErrors();
    setTimeout(() => {
        phoneInputRef.value?.focus();
    }, 30);
}

const profileForm = useForm({
    name: props.profile?.name ?? '',
    first_name: props.profile?.first_name ?? '',
    gender: props.profile?.gender ?? 'homme',
    id_number: props.profile?.id_number ?? '',
    email: props.profile?.email ?? '',
    profession: props.profile?.profession ?? '',
    date_of_birth: props.profile?.date_of_birth ?? '',
    emergency_contact: props.profile?.emergency_contact ?? '',
    description: props.profile?.description ?? '',
});

function saveProfile() {
    profileForm.put('/mon-espace-client/profil', { preserveScroll: true });
}

function logout() {
    router.post('/mon-espace-client/deconnexion');
}

function formatMoney(v) {
    return `${Number(v || 0).toLocaleString('fr-FR')} FCFA`;
}

const customerFullName = computed(() => {
    if (!props.profile) return 'Client MboaCar';
    return [props.profile.first_name, props.profile.name].filter(Boolean).join(' ').trim() || 'Client MboaCar';
});

const customerInitials = computed(() => {
    const parts = customerFullName.value.split(/\s+/).filter(Boolean);
    if (parts.length === 0) return 'MC';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
});

const invoiceSummary = computed(() => {
    const total = props.invoices.reduce((sum, inv) => sum + Number(inv.total_ttc || 0), 0);
    const paid = props.invoices.reduce((sum, inv) => sum + Number(inv.paid_amount || 0), 0);
    return {
        total,
        paid,
        remaining: Math.max(0, total - paid),
    };
});

function statusMeta(status) {
    const s = String(status || '').toLowerCase();
    const map = {
        draft: { label: 'Brouillon', cls: 'cp-badge-slate' },
        pending: { label: 'En attente', cls: 'cp-badge-amber' },
        en_attente: { label: 'En attente', cls: 'cp-badge-amber' },
        confirmed: { label: 'Confirmé', cls: 'cp-badge-blue' },
        in_progress: { label: 'En cours', cls: 'cp-badge-blue' },
        en_cours: { label: 'En cours', cls: 'cp-badge-blue' },
        waiting_parts: { label: 'Attente pièces', cls: 'cp-badge-amber' },
        completed: { label: 'Terminé', cls: 'cp-badge-green' },
        termine: { label: 'Terminé', cls: 'cp-badge-green' },
        ready: { label: 'Prêt', cls: 'cp-badge-green' },
        delivered: { label: 'Livré', cls: 'cp-badge-green' },
        paid: { label: 'Payée', cls: 'cp-badge-green' },
        partial: { label: 'Partiel', cls: 'cp-badge-amber' },
        issued: { label: 'Émise', cls: 'cp-badge-blue' },
        unpaid: { label: 'Impayée', cls: 'cp-badge-red' },
        overdue: { label: 'En retard', cls: 'cp-badge-red' },
        cancelled: { label: 'Annulé', cls: 'cp-badge-red' },
    };
    return map[s] || { label: status || '—', cls: 'cp-badge-slate' };
}

function tenantTypeMeta(type) {
    const t = String(type || 'garage').toLowerCase();
    if (t === 'assurance' || t === 'insurance') {
        return { label: 'Assureur', icon: 'bi-shield-check', cls: 'cp-badge-blue' };
    }
    if (t === 'centre_technique' || t === 'technical_inspection') {
        return { label: 'Visite Technique', icon: 'bi-clipboard2-check', cls: 'cp-badge-green' };
    }
    return { label: 'Garage Auto', icon: 'bi-wrench-adjustable', cls: 'cp-badge-slate' };
}

const tabs = computed(() => [
    { id: 'vehicles', label: 'Véhicules', icon: 'bi-car-front-fill', count: props.vehicles.length },
    { id: 'orders', label: 'Réparations', icon: 'bi-wrench-adjustable', count: props.repairOrders.length },
    { id: 'appointments', label: 'Rendez-vous', icon: 'bi-calendar2-check-fill', count: props.appointments.length },
    { id: 'invoices', label: 'Factures', icon: 'bi-receipt-cutoff', count: props.invoices.length },
    { id: 'garages', label: 'Établissements', icon: 'bi-buildings-fill', count: props.garages.length },
    { id: 'profile', label: 'Mon Profil', icon: 'bi-person-vcard-fill', count: null },
]);
</script>

<template>
    <Head title="Mon Espace Client — MboaCar" />

    <div class="cp-shell min-vh-100 d-flex flex-column">
        <!-- Top Navigation Bar -->
        <header class="cp-topbar">
            <div class="container cp-container d-flex align-items-center justify-content-between gap-3">
                <Link href="/" class="d-flex align-items-center gap-2 text-decoration-none">
                    <img :src="siteLogo" :alt="siteName" class="cp-logo" />
                    <div class="d-flex flex-column lh-1">
                        <span class="cp-brand-name">{{ siteName }}</span>
                        <span class="cp-brand-sub">Espace Client Unique</span>
                    </div>
                </Link>

                <div class="d-flex align-items-center gap-2">
                    <Link href="/" class="cp-nav-btn">
                        <i class="bi bi-house-door"></i>
                        <span class="d-none d-sm-inline">Accueil</span>
                    </Link>
                    <button v-if="authenticated" type="button" class="cp-logout-btn" @click="logout">
                        <i class="bi bi-box-arrow-right"></i>
                        <span class="d-none d-sm-inline">Déconnexion</span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-grow-1 py-4 py-md-5">
            <div class="container cp-container">
                <!-- Flash Alert -->
                <div v-if="flashSuccess" class="cp-alert-success mb-4" role="alert">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <span>{{ flashSuccess }}</span>
                </div>

                <!-- ========================================================= -->
                <!-- UNAUTHENTICATED: PREMIUM SPLIT-CARD WHATSAPP OTP LOGIN    -->
                <!-- ========================================================= -->
                <div v-if="!authenticated" class="cp-auth-wrap">
                    <div class="cp-auth-card">
                        <!-- Left Dark-Navy Brand Panel -->
                        <div class="cp-auth-aside">
                            <div>
                                <span class="cp-aside-pill">
                                    <i class="bi bi-shield-lock-fill"></i>
                                    Compte Client Unique
                                </span>
                                <h1 class="cp-aside-title">
                                    Tout votre historique automobile au même endroit.
                                </h1>
                                <p class="cp-aside-desc">
                                    Accédez en un clic à vos véhicules, carnets numériques, réparations en direct et factures dans tous vos établissements partenaires {{ siteName }}.
                                </p>

                                <ul class="cp-feature-list">
                                    <li>
                                        <span class="cp-feature-icon"><i class="bi bi-qr-code-scan"></i></span>
                                        <div>
                                            <strong>Carnet numérique &amp; échéances</strong>
                                            <span>Vidange, visite technique et assurance toujours à jour.</span>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="cp-feature-icon"><i class="bi bi-activity"></i></span>
                                        <div>
                                            <strong>Suivi des réparations en direct</strong>
                                            <span>Consultez l'avancement de vos ordres et vos factures.</span>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="cp-feature-icon"><i class="bi bi-buildings"></i></span>
                                        <div>
                                            <strong>Multi-garages, visites &amp; assurances</strong>
                                            <span>Un seul profil synchronisé automatiquement partout.</span>
                                        </div>
                                    </li>
                                </ul>
                            </div>

                            <div class="cp-aside-footer">
                                <i class="bi bi-whatsapp text-success"></i>
                                <span>Connexion 100% sécurisée sans mot de passe via WhatsApp.</span>
                            </div>
                        </div>

                        <!-- Right Crisp White OTP Panel -->
                        <div class="cp-auth-form-pane">
                            <div class="cp-step-header">
                                <span class="cp-step-badge" :class="{ active: true }">
                                    <i class="bi bi-phone"></i> 1. Numéro
                                </span>
                                <span class="cp-step-sep"></span>
                                <span class="cp-step-badge" :class="{ active: showOtpStep }">
                                    <i class="bi bi-key"></i> 2. Code WhatsApp
                                </span>
                            </div>

                            <h2 class="cp-form-title">Connexion à votre espace</h2>
                            <p class="cp-form-subtitle">
                                Choisissez l'indicatif de votre pays puis saisissez votre numéro WhatsApp enregistré chez votre professionnel.
                            </p>

                            <!-- Step 1: Country Dial Code + Phone -->
                            <form class="mb-4" @submit.prevent="requestOtp">
                                <label class="cp-label">Indicatif &amp; numéro WhatsApp</label>
                                <div class="cp-phone-group" :class="{ 'is-invalid': otpForm.errors.phone }">
                                    <div class="cp-dial-wrap">
                                        <select
                                            v-model="selectedDialCode"
                                            class="cp-dial-select no-select2"
                                            aria-label="Indicatif téléphonique du pays"
                                        >
                                            <option
                                                v-for="c in countryList"
                                                :key="c.code"
                                                :value="c.dial_code"
                                            >
                                                {{ c.code }} ({{ c.dial_code }}) — {{ c.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <input
                                        ref="phoneInputRef"
                                        :value="localPhone"
                                        type="tel"
                                        inputmode="tel"
                                        class="cp-phone-input"
                                        placeholder="6XX XXX XXX"
                                        autocomplete="tel-national"
                                        required
                                        @input="onLocalPhoneInput"
                                    />
                                </div>

                                <div v-if="fullPhonePreview" class="cp-phone-preview">
                                    <i class="bi bi-whatsapp text-success"></i>
                                    Numéro complet : <strong>{{ fullPhonePreview }}</strong>
                                </div>

                                <div v-if="otpForm.errors.phone" class="cp-error-msg">
                                    <i class="bi bi-exclamation-circle me-1"></i>{{ otpForm.errors.phone }}
                                </div>

                                <button
                                    type="submit"
                                    class="cp-btn-whatsapp w-100 mt-3"
                                    :disabled="otpForm.processing"
                                >
                                    <span v-if="otpForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                                    <i v-else class="bi bi-whatsapp me-2"></i>
                                    {{ showOtpStep ? 'Renvoyer un nouveau code' : 'Recevoir mon code sur WhatsApp' }}
                                </button>
                            </form>

                            <!-- Step 2: 5-Digit Verification Code -->
                            <div v-if="showOtpStep" class="cp-otp-box">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <label class="cp-label mb-0">Code de vérification (5 chiffres)</label>
                                    <button type="button" class="cp-link-muted" @click="resetPhoneStep">
                                        Changer de numéro
                                    </button>
                                </div>
                                <p class="small text-muted mb-3">
                                    Saisissez le code à 5 chiffres reçu sur WhatsApp (valable 5 minutes).
                                </p>

                                <form @submit.prevent="verifyOtp">
                                    <input
                                        :value="verifyForm.code"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        maxlength="5"
                                        class="cp-otp-input"
                                        placeholder="• • • • •"
                                        required
                                        @input="onOtpCodeInput"
                                    />

                                    <div v-if="verifyForm.errors.code" class="cp-error-msg mt-2">
                                        <i class="bi bi-exclamation-circle me-1"></i>{{ verifyForm.errors.code }}
                                    </div>

                                    <button
                                        type="submit"
                                        class="cp-btn-primary w-100 mt-3"
                                        :disabled="verifyForm.processing || verifyForm.code.length < 5"
                                    >
                                        <span v-if="verifyForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                                        <i v-else class="bi bi-unlock-fill me-2"></i>
                                        Vérifier et me connecter
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================================================= -->
                <!-- AUTHENTICATED: MODERN MULTI-ESTABLISHMENT CLIENT PORTAL   -->
                <!-- ========================================================= -->
                <template v-else>
                    <!-- Hero Welcome Banner -->
                    <section class="cp-hero mb-4">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="cp-avatar">{{ customerInitials }}</div>
                                <div>
                                    <div class="cp-hero-kicker">Compte Client Unique {{ siteName }}</div>
                                    <h1 class="cp-hero-name mb-1">Bonjour, {{ customerFullName }}</h1>
                                    <div class="d-flex flex-wrap align-items-center gap-2 cp-hero-meta">
                                        <span v-if="profile?.phone">
                                            <i class="bi bi-whatsapp text-success me-1"></i>{{ profile.phone }}
                                        </span>
                                        <span v-if="profile?.email">
                                            <i class="bi bi-envelope me-1"></i>{{ profile.email }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="cp-hero-btn" @click="activeTab = 'profile'">
                                <i class="bi bi-pencil-square me-1"></i>Mon profil unique
                            </button>
                        </div>

                        <!-- KPI Strip -->
                        <div class="row g-3 mt-3">
                            <div class="col-6 col-lg-3">
                                <div class="cp-kpi" @click="activeTab = 'vehicles'">
                                    <span class="cp-kpi-icon cp-kpi-blue"><i class="bi bi-car-front-fill"></i></span>
                                    <div>
                                        <div class="cp-kpi-val">{{ vehicles.length }}</div>
                                        <div class="cp-kpi-lbl">Véhicules</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-lg-3">
                                <div class="cp-kpi" @click="activeTab = 'orders'">
                                    <span class="cp-kpi-icon cp-kpi-amber"><i class="bi bi-wrench-adjustable"></i></span>
                                    <div>
                                        <div class="cp-kpi-val">{{ repairOrders.length }}</div>
                                        <div class="cp-kpi-lbl">Réparations</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-lg-3">
                                <div class="cp-kpi" @click="activeTab = 'invoices'">
                                    <span class="cp-kpi-icon cp-kpi-green"><i class="bi bi-receipt-cutoff"></i></span>
                                    <div>
                                        <div class="cp-kpi-val">{{ invoices.length }}</div>
                                        <div class="cp-kpi-lbl">Factures</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-lg-3">
                                <div class="cp-kpi" @click="activeTab = 'garages'">
                                    <span class="cp-kpi-icon cp-kpi-indigo"><i class="bi bi-buildings-fill"></i></span>
                                    <div>
                                        <div class="cp-kpi-val">{{ garages.length }}</div>
                                        <div class="cp-kpi-lbl">Établissements</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Tab Navigation -->
                    <nav class="cp-tabs mb-4" aria-label="Navigation espace client">
                        <button
                            v-for="t in tabs"
                            :key="t.id"
                            type="button"
                            class="cp-tab"
                            :class="{ active: activeTab === t.id }"
                            @click="activeTab = t.id"
                        >
                            <i class="bi" :class="t.icon"></i>
                            <span>{{ t.label }}</span>
                            <span v-if="t.count !== null" class="cp-tab-count">{{ t.count }}</span>
                        </button>
                    </nav>

                    <!-- TAB 1: VÉHICULES -->
                    <section v-if="activeTab === 'vehicles'">
                        <div v-if="!vehicles.length" class="cp-empty">
                            <i class="bi bi-car-front"></i>
                            <h3>Aucun véhicule enregistré</h3>
                            <p>Vos véhicules apparaîtront ici dès leur enregistrement dans un garage, centre de visite ou chez votre assureur.</p>
                        </div>

                        <div v-else class="row g-3">
                            <div v-for="v in vehicles" :key="v.id" class="col-md-6">
                                <div class="cp-panel cp-vehicle-card h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                                            <div>
                                                <span class="cp-plate">{{ v.license_plate }}</span>
                                                <h3 class="h6 fw-bold mt-2 mb-0 text-dark">
                                                    {{ v.brand }} {{ v.model }}
                                                    <span v-if="v.year" class="text-muted fw-normal">({{ v.year }})</span>
                                                </h3>
                                            </div>
                                            <span class="cp-badge cp-badge-slate">
                                                <i class="bi bi-building me-1"></i>{{ v.garage_name }}
                                            </span>
                                        </div>

                                        <div v-if="v.mileage" class="small text-muted mb-3">
                                            <i class="bi bi-speedometer2 me-1 text-primary"></i>
                                            Kilométrage relevé : <strong>{{ Number(v.mileage).toLocaleString('fr-FR') }} km</strong>
                                        </div>

                                        <div class="cp-deadlines">
                                            <div class="cp-deadline-chip">
                                                <span class="cp-deadline-lbl"><i class="bi bi-droplet-half text-primary"></i> Vidange</span>
                                                <strong>{{ v.next_service_date || 'Non renseignée' }}</strong>
                                            </div>
                                            <div class="cp-deadline-chip">
                                                <span class="cp-deadline-lbl"><i class="bi bi-clipboard2-check text-success"></i> Visite Tech.</span>
                                                <strong>{{ v.technical_inspection_expires_at || 'Non renseignée' }}</strong>
                                            </div>
                                            <div class="cp-deadline-chip">
                                                <span class="cp-deadline-lbl"><i class="bi bi-shield-check text-info"></i> Assurance</span>
                                                <strong>{{ v.insurance_expires_at || 'Non renseignée' }}</strong>
                                            </div>
                                        </div>
                                    </div>

                                    <div v-if="v.public_token" class="pt-3 mt-3 border-top d-flex justify-content-end">
                                        <a
                                            :href="`/carnet/${v.public_token}`"
                                            target="_blank"
                                            class="cp-btn-outline-sm"
                                        >
                                            <i class="bi bi-qr-code me-1"></i>Ouvrir le carnet numérique
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- TAB 2: RÉPARATIONS -->
                    <section v-if="activeTab === 'orders'" class="cp-panel p-0 overflow-hidden">
                        <div v-if="!repairOrders.length" class="cp-empty border-0 shadow-none">
                            <i class="bi bi-wrench-adjustable"></i>
                            <h3>Aucun ordre de réparation</h3>
                            <p>Vos réparations en cours et passées s'afficheront ici avec leur lien de suivi en direct.</p>
                        </div>

                        <div v-else class="table-responsive">
                            <table class="table cp-table mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>N° Ordre</th>
                                        <th>Établissement</th>
                                        <th>Véhicule</th>
                                        <th>Date</th>
                                        <th>Statut</th>
                                        <th class="text-end">Suivi en direct</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="ro in repairOrders" :key="ro.id">
                                        <td><span class="fw-bold text-dark">{{ ro.order_number }}</span></td>
                                        <td>{{ ro.garage_name }}</td>
                                        <td><span class="cp-plate-sm">{{ ro.vehicle_plate ?? '—' }}</span></td>
                                        <td class="text-muted">{{ ro.opened_at ?? '—' }}</td>
                                        <td>
                                            <span class="cp-badge" :class="statusMeta(ro.status).cls">
                                                {{ statusMeta(ro.status).label }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a
                                                v-if="ro.public_token"
                                                :href="`/suivi/${ro.public_token}`"
                                                target="_blank"
                                                class="cp-btn-outline-sm"
                                            >
                                                <i class="bi bi-broadcast me-1"></i>Suivre en direct
                                            </a>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- TAB 3: RENDEZ-VOUS -->
                    <section v-if="activeTab === 'appointments'" class="cp-panel p-0 overflow-hidden">
                        <div v-if="!appointments.length" class="cp-empty border-0 shadow-none">
                            <i class="bi bi-calendar2-check"></i>
                            <h3>Aucun rendez-vous</h3>
                            <p>Vos prochains rendez-vous et passages en atelier apparaîtront ici.</p>
                        </div>

                        <div v-else class="table-responsive">
                            <table class="table cp-table mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Date &amp; Heure</th>
                                        <th>Établissement</th>
                                        <th>Véhicule &amp; Motif</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="a in appointments" :key="a.id">
                                        <td>
                                            <span class="fw-semibold text-dark">
                                                <i class="bi bi-clock me-1 text-primary"></i>{{ a.scheduled_at }}
                                            </span>
                                        </td>
                                        <td>{{ a.garage_name }}</td>
                                        <td>
                                            <span v-if="a.vehicle_plate" class="cp-plate-sm me-2">{{ a.vehicle_plate }}</span>
                                            <span class="text-muted">{{ a.reason || 'Entretien / Visite' }}</span>
                                        </td>
                                        <td>
                                            <span class="cp-badge" :class="statusMeta(a.status).cls">
                                                {{ statusMeta(a.status).label }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- TAB 4: FACTURES -->
                    <section v-if="activeTab === 'invoices'">
                        <div v-if="invoices.length" class="row g-3 mb-3">
                            <div class="col-md-4">
                                <div class="cp-mini-stat">
                                    <span>Total facturé</span>
                                    <strong>{{ formatMoney(invoiceSummary.total) }}</strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="cp-mini-stat">
                                    <span>Montant réglé</span>
                                    <strong class="text-success">{{ formatMoney(invoiceSummary.paid) }}</strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="cp-mini-stat">
                                    <span>Reste à payer</span>
                                    <strong :class="invoiceSummary.remaining > 0 ? 'text-danger' : 'text-muted'">
                                        {{ formatMoney(invoiceSummary.remaining) }}
                                    </strong>
                                </div>
                            </div>
                        </div>

                        <div class="cp-panel p-0 overflow-hidden">
                            <div v-if="!invoices.length" class="cp-empty border-0 shadow-none">
                                <i class="bi bi-receipt"></i>
                                <h3>Aucune facture</h3>
                                <p>L'historique de vos factures et paiements s'affichera ici.</p>
                            </div>

                            <div v-else class="table-responsive">
                                <table class="table cp-table mb-0 align-middle">
                                    <thead>
                                        <tr>
                                            <th>N° Facture</th>
                                            <th>Établissement</th>
                                            <th>Date</th>
                                            <th>Total TTC</th>
                                            <th>Réglé</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="inv in invoices" :key="inv.id">
                                            <td class="fw-bold text-dark">{{ inv.invoice_number }}</td>
                                            <td>{{ inv.garage_name }}</td>
                                            <td class="text-muted">{{ inv.issued_at ?? '—' }}</td>
                                            <td class="fw-semibold text-dark">{{ formatMoney(inv.total_ttc) }}</td>
                                            <td class="text-success fw-medium">{{ formatMoney(inv.paid_amount) }}</td>
                                            <td>
                                                <span class="cp-badge" :class="statusMeta(inv.status).cls">
                                                    {{ statusMeta(inv.status).label }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <!-- TAB 5: MES ÉTABLISSEMENTS -->
                    <section v-if="activeTab === 'garages'">
                        <div v-if="!garages.length" class="cp-empty">
                            <i class="bi bi-buildings"></i>
                            <h3>Aucun établissement connecté</h3>
                            <p>Vos garages, centres de visite technique et assureurs partenaires apparaîtront ici.</p>
                        </div>

                        <div v-else class="row g-3">
                            <div v-for="g in garages" :key="g.id" class="col-md-4">
                                <div class="cp-panel h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                            <span class="cp-badge" :class="tenantTypeMeta(g.tenant_type).cls">
                                                <i class="bi me-1" :class="tenantTypeMeta(g.tenant_type).icon"></i>
                                                {{ tenantTypeMeta(g.tenant_type).label }}
                                            </span>
                                        </div>
                                        <h3 class="h6 fw-bold text-dark mb-2">{{ g.name }}</h3>
                                        <div class="small text-muted d-flex flex-column gap-1">
                                            <span>
                                                <i class="bi bi-geo-alt me-1 text-primary"></i>{{ g.city || 'Ville non renseignée' }}
                                            </span>
                                            <span v-if="g.phone">
                                                <i class="bi bi-telephone me-1 text-success"></i>
                                                <a :href="`tel:${g.phone}`" class="text-decoration-none text-dark fw-medium">{{ g.phone }}</a>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- TAB 6: MON PROFIL UNIQUE -->
                    <section v-if="activeTab === 'profile'" class="cp-panel">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-3 mb-4 border-bottom">
                            <div>
                                <h2 class="h5 fw-bold text-dark mb-1">Mon Profil Unique {{ siteName }}</h2>
                                <p class="small text-muted mb-0">
                                    Toute modification est automatiquement synchronisée avec vos garages, centres de visite et assureurs partenaires.
                                </p>
                            </div>
                            <span v-if="profile?.phone" class="cp-badge cp-badge-green">
                                <i class="bi bi-whatsapp me-1"></i>{{ profile.phone }}
                            </span>
                        </div>

                        <form @submit.prevent="saveProfile">
                            <div class="row g-3 mb-3">
                                <div class="col-md-5">
                                    <label class="cp-label">Nom <span class="text-danger">*</span></label>
                                    <input v-model="profileForm.name" type="text" class="form-control cp-input" required />
                                    <div v-if="profileForm.errors.name" class="cp-error-msg">{{ profileForm.errors.name }}</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="cp-label">Prénom</label>
                                    <input v-model="profileForm.first_name" type="text" class="form-control cp-input" />
                                </div>
                                <div class="col-md-3">
                                    <label class="cp-label">Sexe <span class="text-danger">*</span></label>
                                    <select v-model="profileForm.gender" class="form-select cp-input no-select2" required>
                                        <option value="homme">Homme</option>
                                        <option value="femme">Femme</option>
                                        <option value="autre">Autre</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="cp-label">N° CNI / Passeport</label>
                                    <input v-model="profileForm.id_number" type="text" class="form-control cp-input" />
                                </div>
                                <div class="col-md-4">
                                    <label class="cp-label">Adresse e-mail</label>
                                    <input v-model="profileForm.email" type="email" class="form-control cp-input" />
                                    <div v-if="profileForm.errors.email" class="cp-error-msg">{{ profileForm.errors.email }}</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="cp-label">Profession</label>
                                    <input v-model="profileForm.profession" type="text" class="form-control cp-input" />
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="cp-label">Date de naissance</label>
                                    <DatePickerInput v-model="profileForm.date_of_birth" />
                                </div>
                                <div class="col-md-6">
                                    <label class="cp-label">Contact d'urgence</label>
                                    <input v-model="profileForm.emergency_contact" type="text" class="form-control cp-input" placeholder="Nom et téléphone" />
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="cp-label">Description / Préférences</label>
                                <textarea
                                    v-model="profileForm.description"
                                    rows="3"
                                    class="form-control cp-input"
                                    placeholder="Informations utiles pour vos garages ou assureurs..."
                                ></textarea>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="cp-btn-primary px-4" :disabled="profileForm.processing">
                                    <span v-if="profileForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                                    <i v-else class="bi bi-check2-circle me-2"></i>
                                    Enregistrer et synchroniser
                                </button>
                            </div>
                        </form>
                    </section>
                </template>
            </div>
        </main>

        <!-- Minimal Footer -->
        <footer class="cp-footer">
            <div class="container cp-container d-flex flex-wrap justify-content-between align-items-center gap-2 small">
                <span>&copy; {{ new Date().getFullYear() }} {{ siteName }} — Espace Client Sécurisé</span>
                <Link href="/" class="text-decoration-none text-muted">Retour au site principal</Link>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.cp-shell {
    background:
        radial-gradient(circle at 12% 0%, rgba(37, 99, 235, 0.08), transparent 42%),
        radial-gradient(circle at 90% 10%, rgba(16, 185, 129, 0.07), transparent 38%),
        #f1f5f9;
    color: #0f172a;
}

.cp-container {
    max-width: 1060px;
}

/* Topbar */
.cp-topbar {
    background: #0f172a;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    padding: 0.85rem 0;
}

.cp-logo {
    height: 36px;
    width: auto;
    border-radius: 8px;
    object-fit: contain;
}

.cp-brand-name {
    color: #ffffff;
    font-weight: 800;
    font-size: 1.05rem;
    letter-spacing: -0.02em;
}

.cp-brand-sub {
    color: #94a3b8;
    font-size: 0.72rem;
    font-weight: 500;
    margin-top: 2px;
}

.cp-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.9rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    color: #e2e8f0;
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.18s ease, color 0.18s ease;
}

.cp-nav-btn:hover {
    background: rgba(255, 255, 255, 0.16);
    color: #ffffff;
}

.cp-logout-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.95rem;
    border-radius: 999px;
    border: 1px solid rgba(248, 113, 113, 0.35);
    background: rgba(239, 68, 68, 0.14);
    color: #fecaca;
    font-size: 0.84rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.18s ease;
}

.cp-logout-btn:hover {
    background: #ef4444;
    border-color: #ef4444;
    color: #ffffff;
}

/* Flash Alert */
.cp-alert-success {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
    border-radius: 14px;
    padding: 0.9rem 1.15rem;
    font-size: 0.9rem;
    font-weight: 500;
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.08);
}

/* Auth Split Card */
.cp-auth-wrap {
    max-width: 960px;
    margin: 0.5rem auto 1.5rem;
}

.cp-auth-card {
    display: grid;
    grid-template-columns: 1fr 1.08fr;
    background: #ffffff;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
    border: 1px solid rgba(15, 23, 42, 0.06);
}

.cp-auth-aside {
    background:
        radial-gradient(circle at 85% 15%, rgba(37, 99, 235, 0.35), transparent 55%),
        radial-gradient(circle at 15% 88%, rgba(16, 185, 129, 0.22), transparent 50%),
        linear-gradient(155deg, #0f172a 0%, #1e293b 100%);
    color: #ffffff;
    padding: 2.5rem 2.25rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 2rem;
}

.cp-aside-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.38rem 0.85rem;
    border-radius: 999px;
    background: rgba(59, 130, 246, 0.2);
    border: 1px solid rgba(96, 165, 250, 0.35);
    color: #bfdbfe;
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    margin-bottom: 1.25rem;
}

.cp-aside-title {
    font-size: 1.65rem;
    font-weight: 800;
    line-height: 1.22;
    letter-spacing: -0.025em;
    margin-bottom: 0.85rem;
    color: #ffffff;
}

.cp-aside-desc {
    color: #cbd5e1;
    font-size: 0.92rem;
    line-height: 1.55;
    margin-bottom: 1.75rem;
}

.cp-feature-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.cp-feature-list li {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
}

.cp-feature-icon {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border-radius: 11px;
    background: rgba(255, 255, 255, 0.09);
    border: 1px solid rgba(255, 255, 255, 0.14);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #60a5fa;
    font-size: 1.1rem;
    line-height: 1;
}

.cp-feature-icon i {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.cp-feature-list li > div > strong {
    display: block;
    font-size: 0.9rem;
    font-weight: 700;
    color: #f8fafc;
}

.cp-feature-list li > div > span {
    display: block;
    font-size: 0.8rem;
    color: #94a3b8;
    line-height: 1.4;
}

.cp-aside-footer {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding-top: 1.25rem;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    font-size: 0.8rem;
    color: #cbd5e1;
}

/* Form Pane */
.cp-auth-form-pane {
    padding: 2.5rem 2.4rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.cp-step-header {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    margin-bottom: 1.5rem;
}

.cp-step-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.76rem;
    font-weight: 700;
    padding: 0.32rem 0.7rem;
    border-radius: 999px;
    background: #f1f5f9;
    color: #64748b;
}

.cp-step-badge.active {
    background: #eff6ff;
    color: #1d4ed8;
}

.cp-step-sep {
    flex: 1;
    height: 2px;
    background: #e2e8f0;
    border-radius: 2px;
}

.cp-form-title {
    font-size: 1.45rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    margin-bottom: 0.4rem;
}

.cp-form-subtitle {
    color: #64748b;
    font-size: 0.88rem;
    line-height: 1.5;
    margin-bottom: 1.5rem;
}

.cp-label {
    display: block;
    font-size: 0.82rem;
    font-weight: 700;
    color: #334155;
    margin-bottom: 0.45rem;
}

/* Phone Input with Dial Code Selector */
.cp-phone-group {
    display: flex;
    align-items: stretch;
    border: 1.5px solid #cbd5e1;
    border-radius: 14px;
    overflow: hidden;
    background: #ffffff;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.cp-phone-group:focus-within {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
}

.cp-phone-group.is-invalid {
    border-color: #ef4444;
}

.cp-dial-wrap {
    flex: 0 0 155px;
    background: #f8fafc;
    border-right: 1.5px solid #e2e8f0;
    display: flex;
    align-items: center;
}

.cp-dial-select {
    width: 100%;
    height: 100%;
    border: 0;
    background: transparent;
    padding: 0.78rem 0.65rem;
    font-size: 0.86rem;
    font-weight: 700;
    color: #0f172a;
    outline: none;
    cursor: pointer;
}

.cp-phone-input {
    flex: 1;
    min-width: 0;
    border: 0;
    padding: 0.78rem 0.95rem;
    font-size: 1rem;
    font-weight: 600;
    color: #0f172a;
    outline: none;
    background: transparent;
    letter-spacing: 0.02em;
}

.cp-phone-input::placeholder {
    color: #94a3b8;
    font-weight: 400;
}

.cp-phone-preview {
    margin-top: 0.5rem;
    font-size: 0.8rem;
    color: #475569;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.cp-error-msg {
    color: #dc2626;
    font-size: 0.8rem;
    font-weight: 600;
    margin-top: 0.45rem;
}

.cp-btn-whatsapp {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.85rem 1.25rem;
    border: 0;
    border-radius: 13px;
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
    color: #ffffff;
    font-size: 0.94rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 10px 22px rgba(22, 163, 74, 0.24);
    transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}

.cp-btn-whatsapp:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 14px 28px rgba(22, 163, 74, 0.3);
}

.cp-btn-whatsapp:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

.cp-btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.82rem 1.3rem;
    border: 0;
    border-radius: 13px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    font-size: 0.93rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 10px 22px rgba(37, 99, 235, 0.25);
    transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}

.cp-btn-primary:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 14px 28px rgba(37, 99, 235, 0.32);
}

.cp-btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.cp-otp-box {
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem;
}

.cp-otp-input {
    width: 100%;
    text-align: center;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 1.65rem;
    font-weight: 800;
    letter-spacing: 0.45em;
    padding: 0.7rem 1rem;
    border-radius: 12px;
    border: 1.5px solid #cbd5e1;
    background: #ffffff;
    color: #0f172a;
    outline: none;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.cp-otp-input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
}

.cp-link-muted {
    border: 0;
    background: none;
    padding: 0;
    font-size: 0.78rem;
    font-weight: 600;
    color: #2563eb;
    cursor: pointer;
}

.cp-link-muted:hover {
    text-decoration: underline;
}

/* Authenticated Hero */
.cp-hero {
    background:
        radial-gradient(circle at 92% 10%, rgba(37, 99, 235, 0.34), transparent 48%),
        linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    border-radius: 22px;
    padding: 1.75rem;
    color: #ffffff;
    box-shadow: 0 20px 44px rgba(15, 23, 42, 0.14);
}

.cp-avatar {
    width: 58px;
    height: 58px;
    border-radius: 16px;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: #ffffff;
    font-size: 1.2rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 10px 24px rgba(37, 99, 235, 0.35);
}

.cp-hero-kicker {
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 700;
    color: #93c5fd;
}

.cp-hero-name {
    font-size: 1.4rem;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.02em;
}

.cp-hero-meta {
    font-size: 0.84rem;
    color: #cbd5e1;
}

.cp-hero-btn {
    border: 1px solid rgba(255, 255, 255, 0.2);
    background: rgba(255, 255, 255, 0.1);
    color: #ffffff;
    border-radius: 12px;
    padding: 0.55rem 1rem;
    font-size: 0.84rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s ease;
}

.cp-hero-btn:hover {
    background: rgba(255, 255, 255, 0.18);
}

/* KPI Cards inside Hero */
.cp-kpi {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 0.95rem 1rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.07);
    border: 1px solid rgba(255, 255, 255, 0.1);
    cursor: pointer;
    transition: background 0.18s ease, transform 0.15s ease;
}

.cp-kpi:hover {
    background: rgba(255, 255, 255, 0.12);
    transform: translateY(-2px);
}

.cp-kpi-icon {
    width: 40px;
    height: 40px;
    border-radius: 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    flex-shrink: 0;
}

.cp-kpi-blue { background: rgba(59, 130, 246, 0.22); color: #93c5fd; }
.cp-kpi-amber { background: rgba(245, 158, 11, 0.22); color: #fcd34d; }
.cp-kpi-green { background: rgba(16, 185, 129, 0.22); color: #6ee7b7; }
.cp-kpi-indigo { background: rgba(129, 140, 248, 0.22); color: #c7d2fe; }

.cp-kpi-val {
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1.1;
    color: #ffffff;
}

.cp-kpi-lbl {
    font-size: 0.78rem;
    color: #cbd5e1;
}

/* Tabs */
.cp-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    background: #ffffff;
    padding: 0.45rem;
    border-radius: 16px;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05);
    border: 1px solid rgba(15, 23, 42, 0.06);
}

.cp-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.58rem 0.95rem;
    border-radius: 11px;
    border: 0;
    background: transparent;
    color: #475569;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.16s ease;
}

.cp-tab:hover {
    background: #f1f5f9;
    color: #0f172a;
}

.cp-tab.active {
    background: #0f172a;
    color: #ffffff;
}

.cp-tab-count {
    font-size: 0.72rem;
    padding: 0.1rem 0.48rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.08);
    color: inherit;
}

.cp-tab.active .cp-tab-count {
    background: rgba(255, 255, 255, 0.18);
}

/* Panels & Cards */
.cp-panel {
    background: #ffffff;
    border-radius: 18px;
    padding: 1.35rem;
    border: 1px solid rgba(15, 23, 42, 0.06);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
}

.cp-plate {
    display: inline-block;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.92rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    padding: 0.28rem 0.72rem;
    border-radius: 8px;
    background: #f8fafc;
    border: 1.5px solid #cbd5e1;
    color: #0f172a;
}

.cp-plate-sm {
    display: inline-block;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 0.16rem 0.5rem;
    border-radius: 6px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #0f172a;
}

.cp-deadlines {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.55rem;
}

.cp-deadline-chip {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 11px;
    padding: 0.55rem 0.65rem;
    font-size: 0.75rem;
}

.cp-deadline-lbl {
    display: block;
    color: #64748b;
    margin-bottom: 0.15rem;
}

.cp-deadline-chip strong {
    color: #0f172a;
    font-size: 0.78rem;
}

.cp-btn-outline-sm {
    display: inline-flex;
    align-items: center;
    padding: 0.42rem 0.85rem;
    border-radius: 10px;
    border: 1.5px solid #2563eb;
    color: #2563eb;
    background: #eff6ff;
    font-size: 0.8rem;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.15s ease;
}

.cp-btn-outline-sm:hover {
    background: #2563eb;
    color: #ffffff;
}

/* Badges */
.cp-badge {
    display: inline-flex;
    align-items: center;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.28rem 0.68rem;
    border-radius: 999px;
}

.cp-badge-slate { background: #f1f5f9; color: #334155; }
.cp-badge-blue { background: #dbeafe; color: #1d4ed8; }
.cp-badge-green { background: #dcfce7; color: #15803d; }
.cp-badge-amber { background: #fef3c7; color: #b45309; }
.cp-badge-red { background: #fee2e2; color: #b91c1c; }

/* Table */
.cp-table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.9rem 1.1rem;
    border-bottom: 1px solid #e2e8f0;
}

.cp-table tbody td {
    padding: 0.9rem 1.1rem;
    font-size: 0.86rem;
    border-color: #f1f5f9;
}

.cp-mini-stat {
    background: #ffffff;
    border-radius: 14px;
    padding: 1rem 1.2rem;
    border: 1px solid rgba(15, 23, 42, 0.06);
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
}

.cp-mini-stat span {
    display: block;
    font-size: 0.78rem;
    color: #64748b;
    margin-bottom: 0.2rem;
}

.cp-mini-stat strong {
    font-size: 1.15rem;
    font-weight: 800;
    color: #0f172a;
}

/* Form inputs */
.cp-input {
    border-radius: 11px;
    border: 1.5px solid #cbd5e1;
    padding: 0.62rem 0.85rem;
    font-size: 0.9rem;
}

.cp-input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
}

/* Empty State */
.cp-empty {
    background: #ffffff;
    border-radius: 18px;
    padding: 3rem 1.5rem;
    text-align: center;
    border: 1px solid rgba(15, 23, 42, 0.06);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
}

.cp-empty i {
    font-size: 2.4rem;
    color: #94a3b8;
    margin-bottom: 0.75rem;
    display: inline-block;
}

.cp-empty h3 {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 0.35rem;
}

.cp-empty p {
    font-size: 0.86rem;
    color: #64748b;
    max-width: 420px;
    margin: 0 auto;
}

/* Footer */
.cp-footer {
    border-top: 1px solid #e2e8f0;
    background: #ffffff;
    padding: 1rem 0;
    color: #64748b;
}

/* Responsive */
@media (max-width: 860px) {
    .cp-auth-card {
        grid-template-columns: 1fr;
    }
    .cp-auth-aside {
        padding: 1.75rem 1.5rem;
    }
    .cp-auth-form-pane {
        padding: 1.75rem 1.5rem;
    }
    .cp-deadlines {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .cp-dial-wrap {
        flex: 0 0 130px;
    }
}
</style>
