<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import TimePickerInput from '@/Components/TimePickerInput.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    linkStatus: { type: String, required: true },
    token: { type: String, required: true },
    label: { type: String, default: null },
    expiresAtFormatted: { type: String, default: null },
    tenantName: { type: String, default: 'MboaCar Garage' },
    agencyName: { type: String, default: null },
    defaultDialCode: { type: String, default: '+237' },
    countries: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const scheduleDate = ref('');
const scheduleTime = ref('09:00');

const form = useForm({
    name: '',
    first_name: '',
    gender: 'homme',
    id_number: '',
    dial_code: props.defaultDialCode || '+237',
    phone: '',
    profession: '',
    date_of_birth: '',
    emergency_contact: '',
    description: '',
    vehicle_plate: '',
    vehicle_brand: '',
    vehicle_model: '',
    reason: 'entretien',
    scheduled_at: '',
    notes: '',
});

function submitForm() {
    form.scheduled_at = scheduleDate.value ? `${scheduleDate.value} ${scheduleTime.value || '09:00'}:00` : '';
    form.post(`/rdv/${props.token}`);
}
</script>

<template>
    <Head :title="`Prise de rendez-vous — ${tenantName}`" />

    <div class="min-vh-100 bg-light py-4 py-md-5">
        <div class="container" style="max-width: 780px;">
            <div class="text-center mb-4">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 mb-2">
                    <i class="bi bi-shield-lock-fill me-1"></i>Lien sécurisé à usage unique
                </span>
                <h1 class="h3 fw-bold mb-1">{{ tenantName }}</h1>
                <p v-if="agencyName" class="text-muted small mb-0">Agence : {{ agencyName }}</p>
            </div>

            <div v-if="flashSuccess" class="alert alert-success shadow-sm">
                <i class="bi bi-check-circle-fill me-2"></i>{{ flashSuccess }}
            </div>

            <div v-if="form.errors.link" class="alert alert-danger shadow-sm">
                <i class="bi bi-exclamation-octagon-fill me-2"></i>{{ form.errors.link }}
            </div>

            <!-- Lien mort / expiré / déjà utilisé -->
            <div v-if="linkStatus !== 'active'" class="card border-0 shadow-sm text-center p-4 p-md-5">
                <div class="mb-3">
                    <i
                        class="bi fs-1"
                        :class="linkStatus === 'submitted' || linkStatus === 'confirmed' ? 'bi-check2-circle text-success' : 'bi-x-octagon text-danger'"
                    ></i>
                </div>
                <h2 class="h5 fw-bold mb-2">
                    <template v-if="linkStatus === 'submitted'">Demande déjà enregistrée</template>
                    <template v-else-if="linkStatus === 'confirmed'">Rendez-vous confirmé — Lien clôturé</template>
                    <template v-else-if="linkStatus === 'expired'">Ce lien de rendez-vous a expiré</template>
                    <template v-else-if="linkStatus === 'cancelled'">Ce lien a été désactivé</template>
                    <template v-else>Lien de rendez-vous introuvable</template>
                </h2>
                <p class="text-muted small mb-0">
                    <template v-if="linkStatus === 'submitted'">
                        Ce lien sécurisé à usage unique a déjà été utilisé pour soumettre votre demande. Vous recevrez un message WhatsApp dès que le garage aura confirmé votre créneau.
                    </template>
                    <template v-else-if="linkStatus === 'confirmed'">
                        Ce rendez-vous a déjà été confirmé par le garage. Pour des raisons de sécurité, ce lien n'est plus utilisable.
                    </template>
                    <template v-else-if="linkStatus === 'expired'">
                        Ce lien était valable pendant 24 heures (jusqu'au {{ expiresAtFormatted ?? 'délai dépassé' }}) et n'est plus actif. Veuillez contacter {{ tenantName }} pour obtenir un nouveau lien.
                    </template>
                    <template v-else>
                        Ce lien n'est plus valide ou a déjà été utilisé. Veuillez contacter le garage pour obtenir un nouveau lien sécurisé.
                    </template>
                </p>
            </div>

            <!-- Formulaire actif -->
            <div v-else class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h2 class="h5 fw-bold mb-0">Demande de rendez-vous</h2>
                        <small class="text-muted">Remplissez vos informations ci-dessous pour réserver votre passage à l'atelier.</small>
                    </div>
                    <span v-if="expiresAtFormatted" class="badge bg-warning-subtle text-dark border border-warning-subtle">
                        <i class="bi bi-clock me-1"></i>Valable jusqu'au {{ expiresAtFormatted }}
                    </span>
                </div>

                <form class="card-body p-3 p-md-4" @submit.prevent="submitForm">
                    <h6 class="fw-bold text-primary mb-3"><i class="bi bi-person-vcard me-1"></i>Vos informations personnelles</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Nom <span class="text-danger">*</span></label>
                            <input v-model="form.name" type="text" class="form-control" required />
                            <div v-if="form.errors.name" class="text-danger small mt-1">{{ form.errors.name }}</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Prénom (facultatif)</label>
                            <input v-model="form.first_name" type="text" class="form-control" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Sexe <span class="text-danger">*</span></label>
                            <select v-model="form.gender" class="form-select" required>
                                <option value="homme">Homme</option>
                                <option value="femme">Femme</option>
                                <option value="autre">Autre</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">N° CNI ou Passeport <span class="text-danger">*</span></label>
                            <input v-model="form.id_number" type="text" class="form-control" required />
                            <div v-if="form.errors.id_number" class="text-danger small mt-1">{{ form.errors.id_number }}</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Téléphone WhatsApp <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select v-model="form.dial_code" class="form-select" style="max-width: 110px;">
                                    <option :value="defaultDialCode">{{ defaultDialCode }}</option>
                                    <option v-for="c in countries" :key="c.code" :value="c.dial_code">{{ c.code }} ({{ c.dial_code }})</option>
                                </select>
                                <input v-model="form.phone" type="tel" class="form-control" placeholder="6XX XXX XXX" required />
                            </div>
                            <div v-if="form.errors.phone" class="text-danger small mt-1">{{ form.errors.phone }}</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">Profession (facultatif)</label>
                            <input v-model="form.profession" type="text" class="form-control" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Date de naissance (facultatif)</label>
                            <DatePickerInput v-model="form.date_of_birth" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Contact d'urgence (facultatif)</label>
                            <input v-model="form.emergency_contact" type="text" class="form-control" />
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small">Description / Précisions (facultatif)</label>
                        <textarea v-model="form.description" rows="2" class="form-control"></textarea>
                    </div>

                    <hr class="my-3" />
                    <h6 class="fw-bold text-primary mb-3"><i class="bi bi-car-front me-1"></i>Véhicule (facultatif)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label small">Immatriculation</label>
                            <input v-model="form.vehicle_plate" type="text" class="form-control" placeholder="Ex: LT 237 AA" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Marque</label>
                            <input v-model="form.vehicle_brand" type="text" class="form-control" placeholder="Ex: Toyota" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Modèle</label>
                            <input v-model="form.vehicle_model" type="text" class="form-control" placeholder="Ex: Corolla" />
                        </div>
                    </div>

                    <hr class="my-3" />
                    <h6 class="fw-bold text-primary mb-3"><i class="bi bi-calendar-event me-1"></i>Créneau souhaité</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Motif <span class="text-danger">*</span></label>
                            <select v-model="form.reason" class="form-select" required>
                                <option value="entretien">Entretien</option>
                                <option value="panne">Panne</option>
                                <option value="controle">Contrôle</option>
                                <option value="accident">Accident</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Date souhaitée <span class="text-danger">*</span></label>
                            <DatePickerInput v-model="scheduleDate" required />
                            <div v-if="form.errors.scheduled_at" class="text-danger small mt-1">{{ form.errors.scheduled_at }}</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Heure <span class="text-danger">*</span></label>
                            <TimePickerInput v-model="scheduleTime" required />
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small">Notes complémentaires</label>
                        <textarea v-model="form.notes" rows="2" class="form-control" placeholder="Symptômes constatés, disponibilités..."></textarea>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg fw-semibold" :disabled="form.processing">
                            <i class="bi bi-send-check me-2"></i>Envoyer ma demande de rendez-vous
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
