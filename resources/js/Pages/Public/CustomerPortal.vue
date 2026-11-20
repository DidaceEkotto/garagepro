<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    authenticated: { type: Boolean, default: false },
    otpPendingPhone: { type: String, default: null },
    profile: { type: Object, default: null },
    garages: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    repairOrders: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const activeTab = ref('vehicles');

const otpForm = useForm({
    phone: props.otpPendingPhone ?? '',
});

const verifyForm = useForm({
    phone: props.otpPendingPhone ?? '',
    code: '',
});

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

function requestOtp() {
    otpForm.post('/mon-espace-client/otp', {
        onSuccess: () => {
            verifyForm.phone = otpForm.phone;
        },
    });
}

function verifyOtp() {
    if (!verifyForm.phone && otpForm.phone) {
        verifyForm.phone = otpForm.phone;
    }
    verifyForm.post('/mon-espace-client/verifier');
}

function saveProfile() {
    profileForm.put('/mon-espace-client/profil', { preserveScroll: true });
}

function logout() {
    router.post('/mon-espace-client/deconnexion');
}

function formatMoney(v) {
    return `${Number(v || 0).toLocaleString('fr-FR')} FCFA`;
}
</script>

<template>
    <Head title="Portail Client Unique MboaCar" />

    <div class="min-vh-100 bg-light py-4 py-md-5">
        <div class="container" style="max-width: 980px;">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-1">
                        <i class="bi bi-person-badge me-1"></i>Compte Client Unique MboaCar
                    </span>
                    <h1 class="h3 fw-bold mb-0">Mon Espace Client Multi-Garages</h1>
                    <p class="text-muted small mb-0">Retrouvez vos véhicules, suivis, factures et identité unique dans tous vos garages partenaires</p>
                </div>
                <button v-if="authenticated" type="button" class="btn btn-outline-danger btn-sm" @click="logout">
                    <i class="bi bi-box-arrow-right me-1"></i>Déconnexion
                </button>
            </div>

            <div v-if="flashSuccess" class="alert alert-success shadow-sm small">
                <i class="bi bi-check-circle-fill me-2"></i>{{ flashSuccess }}
            </div>

            <!-- Connexion OTP WhatsApp -->
            <div v-if="!authenticated" class="row justify-content-center">
                <div class="col-md-7">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h2 class="h5 fw-bold mb-2">Connexion sécurisée par WhatsApp</h2>
                            <p class="text-muted small mb-4">
                                Saisissez le numéro WhatsApp enregistré dans votre garage pour recevoir un code de connexion à 5 chiffres.
                            </p>

                            <form class="mb-4" @submit.prevent="requestOtp">
                                <label class="form-label small fw-semibold">Numéro WhatsApp</label>
                                <div class="input-group mb-2">
                                    <input v-model="otpForm.phone" type="tel" class="form-control" placeholder="+237 6XX XXX XXX" required />
                                    <button type="submit" class="btn btn-success" :disabled="otpForm.processing">
                                        <i class="bi bi-whatsapp me-1"></i>Recevoir le code
                                    </button>
                                </div>
                                <div v-if="otpForm.errors.phone" class="text-danger small">{{ otpForm.errors.phone }}</div>
                            </form>

                            <form v-if="otpPendingPhone || verifyForm.phone" class="border-top pt-3" @submit.prevent="verifyOtp">
                                <label class="form-label small fw-semibold">Code de vérification (5 chiffres)</label>
                                <div class="input-group mb-2">
                                    <input v-model="verifyForm.code" type="text" maxlength="5" class="form-control" placeholder="12345" required />
                                    <button type="submit" class="btn btn-primary" :disabled="verifyForm.processing">
                                        Se connecter
                                    </button>
                                </div>
                                <div v-if="verifyForm.errors.code" class="text-danger small">{{ verifyForm.errors.code }}</div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Espace Client Authentifié -->
            <template v-else>
                <ul class="nav nav-pills flex-wrap gap-1 mb-3">
                    <li class="nav-item">
                        <button type="button" class="nav-link" :class="{ active: activeTab === 'vehicles' }" @click="activeTab = 'vehicles'">
                            <i class="bi bi-car-front me-1"></i>Véhicules ({{ vehicles.length }})
                        </button>
                    </li>
                    <li class="nav-item">
                        <button type="button" class="nav-link" :class="{ active: activeTab === 'orders' }" @click="activeTab = 'orders'">
                            <i class="bi bi-wrench me-1"></i>Réparations ({{ repairOrders.length }})
                        </button>
                    </li>
                    <li class="nav-item">
                        <button type="button" class="nav-link" :class="{ active: activeTab === 'appointments' }" @click="activeTab = 'appointments'">
                            <i class="bi bi-calendar-check me-1"></i>Rendez-vous ({{ appointments.length }})
                        </button>
                    </li>
                    <li class="nav-item">
                        <button type="button" class="nav-link" :class="{ active: activeTab === 'invoices' }" @click="activeTab = 'invoices'">
                            <i class="bi bi-receipt me-1"></i>Factures ({{ invoices.length }})
                        </button>
                    </li>
                    <li class="nav-item">
                        <button type="button" class="nav-link" :class="{ active: activeTab === 'garages' }" @click="activeTab = 'garages'">
                            <i class="bi bi-building me-1"></i>Mes Garages ({{ garages.length }})
                        </button>
                    </li>
                    <li class="nav-item">
                        <button type="button" class="nav-link" :class="{ active: activeTab === 'profile' }" @click="activeTab = 'profile'">
                            <i class="bi bi-person-gear me-1"></i>Mon Profil Unique
                        </button>
                    </li>
                </ul>

                <!-- Véhicules -->
                <div v-if="activeTab === 'vehicles'" class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle small">
                            <thead class="table-light">
                                <tr>
                                    <th>Véhicule</th>
                                    <th>Garage</th>
                                    <th>Échéances</th>
                                    <th class="text-end">Carnet numérique</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!vehicles.length">
                                    <td colspan="4" class="text-center text-muted py-4">Aucun véhicule enregistré.</td>
                                </tr>
                                <tr v-for="v in vehicles" :key="v.id">
                                    <td>
                                        <div class="fw-bold">{{ v.license_plate }}</div>
                                        <div class="text-muted">{{ v.brand }} {{ v.model }} {{ v.year || '' }}</div>
                                    </td>
                                    <td>{{ v.garage_name }}</td>
                                    <td>
                                        <div v-if="v.next_service_date">Vidange : {{ v.next_service_date }}</div>
                                        <div v-if="v.technical_inspection_expires_at">Visite : {{ v.technical_inspection_expires_at }}</div>
                                        <div v-if="v.insurance_expires_at">Assurance : {{ v.insurance_expires_at }}</div>
                                    </td>
                                    <td class="text-end">
                                        <a v-if="v.public_token" :href="`/carnet/${v.public_token}`" target="_blank" class="btn btn-outline-primary btn-sm">
                                            Ouvrir le carnet
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Ordres de réparation -->
                <div v-if="activeTab === 'orders'" class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle small">
                            <thead class="table-light">
                                <tr>
                                    <th>N° OR</th>
                                    <th>Garage</th>
                                    <th>Véhicule</th>
                                    <th>Statut</th>
                                    <th class="text-end">Suivi en direct</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!repairOrders.length">
                                    <td colspan="5" class="text-center text-muted py-4">Aucun ordre de réparation.</td>
                                </tr>
                                <tr v-for="ro in repairOrders" :key="ro.id">
                                    <td class="fw-bold">{{ ro.order_number }}</td>
                                    <td>{{ ro.garage_name }}</td>
                                    <td>{{ ro.vehicle_plate ?? '-' }}</td>
                                    <td><span class="badge bg-info text-dark">{{ ro.status }}</span></td>
                                    <td class="text-end">
                                        <a v-if="ro.public_token" :href="`/suivi/${ro.public_token}`" target="_blank" class="btn btn-outline-primary btn-sm">
                                            Suivre en direct
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Rendez-vous -->
                <div v-if="activeTab === 'appointments'" class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle small">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Garage</th>
                                    <th>Véhicule / Motif</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!appointments.length">
                                    <td colspan="4" class="text-center text-muted py-4">Aucun rendez-vous.</td>
                                </tr>
                                <tr v-for="a in appointments" :key="a.id">
                                    <td class="fw-semibold">{{ a.scheduled_at }}</td>
                                    <td>{{ a.garage_name }}</td>
                                    <td>{{ a.vehicle_plate ?? '-' }} • {{ a.reason }}</td>
                                    <td><span class="badge bg-info text-dark">{{ a.status }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Factures -->
                <div v-if="activeTab === 'invoices'" class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle small">
                            <thead class="table-light">
                                <tr>
                                    <th>N° Facture</th>
                                    <th>Garage</th>
                                    <th>Montant TTC</th>
                                    <th>Payé</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!invoices.length">
                                    <td colspan="5" class="text-center text-muted py-4">Aucune facture.</td>
                                </tr>
                                <tr v-for="inv in invoices" :key="inv.id">
                                    <td class="fw-bold">{{ inv.invoice_number }}</td>
                                    <td>{{ inv.garage_name }}</td>
                                    <td class="fw-semibold">{{ formatMoney(inv.total_ttc) }}</td>
                                    <td>{{ formatMoney(inv.paid_amount) }}</td>
                                    <td><span class="badge bg-light text-dark border">{{ inv.status }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Mes Garages -->
                <div v-if="activeTab === 'garages'" class="row g-3">
                    <div v-for="g in garages" :key="g.id" class="col-md-4">
                        <div class="card border-0 shadow-sm p-3 h-100">
                            <div class="fw-bold">{{ g.name }}</div>
                            <div class="small text-muted">{{ g.city || '-' }}</div>
                            <div class="small text-muted">{{ g.phone || '-' }}</div>
                        </div>
                    </div>
                </div>

                <!-- Profil Unique -->
                <div v-if="activeTab === 'profile'" class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold small py-3">
                        Mon Profil Unique MboaCar (synchronisé automatiquement avec vos garages)
                    </div>
                    <form class="card-body p-4" @submit.prevent="saveProfile">
                        <div class="row g-3 mb-3">
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Nom <span class="text-danger">*</span></label>
                                <input v-model="profileForm.name" type="text" class="form-control" required />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Prénom</label>
                                <input v-model="profileForm.first_name" type="text" class="form-control" />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Sexe <span class="text-danger">*</span></label>
                                <select v-model="profileForm.gender" class="form-select" required>
                                    <option value="homme">Homme</option>
                                    <option value="femme">Femme</option>
                                    <option value="autre">Autre</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">N° CNI / Passeport</label>
                                <input v-model="profileForm.id_number" type="text" class="form-control" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">E-mail</label>
                                <input v-model="profileForm.email" type="email" class="form-control" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Profession</label>
                                <input v-model="profileForm.profession" type="text" class="form-control" />
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Date de naissance</label>
                                <DatePickerInput v-model="profileForm.date_of_birth" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Contact d'urgence</label>
                                <input v-model="profileForm.emergency_contact" type="text" class="form-control" />
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-semibold">Description / Préférences</label>
                            <textarea v-model="profileForm.description" rows="2" class="form-control"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary px-4" :disabled="profileForm.processing">
                            Enregistrer et synchroniser
                        </button>
                    </form>
                </div>
            </template>
        </div>
    </div>
</template>
