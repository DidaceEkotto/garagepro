<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TimePickerInput from '@/Components/TimePickerInput.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    appointments: Object,
    appointmentLinks: { type: Array, default: () => [] },
    agencies: Array,
    customers: Array,
    vehicles: Array,
    mechanics: Array,
    defaultDialCode: { type: String, default: '+237' },
    countries: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const isNewCustomer = ref(false);
const scheduleDate = ref('');
const scheduleTime = ref('09:00');
const searchQuery = ref('');
const activeFilter = ref('tous');
const copiedLinkId = ref(null);
const inspectingLink = ref(null);
const manualFollowupAppointment = ref(null);

const linkForm = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    label: '',
});

const validateLinkForm = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    create_customer: true,
    customer_id: '',
    bay_name: '',
    assigned_mechanic_id: '',
    scheduled_at: '',
    duration_minutes: 60,
    notes: '',
});

const manualFollowupForm = useForm({
    custom_message: '',
});

const submittedLinks = computed(() => props.appointmentLinks.filter((l) => l.status === 'submitted'));
const canNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    customer_id: '',
    new_customer_name: '',
    new_customer_first_name: '',
    new_customer_gender: 'homme',
    new_customer_id_number: '',
    new_customer_dial_code: props.defaultDialCode || '+237',
    new_customer_phone: '',
    new_customer_profession: '',
    new_customer_date_of_birth: '',
    new_customer_emergency_contact: '',
    new_customer_description: '',
    new_customer_photo: null,
    vehicle_id: '',
    assigned_mechanic_id: '',
    bay_name: 'Pont 1',
    channel: 'telephone',
    scheduled_at: '',
    duration_minutes: 60,
    reason: 'entretien',
    notes: '',
});

const filteredVehicles = computed(() => {
    if (!form.customer_id) return props.vehicles ?? [];
    return (props.vehicles ?? []).filter((v) => Number(v.customer_id) === Number(form.customer_id));
});

function syncVehicleSelect2() {
    nextTick(() => {
        if (window.$ && window.$.fn?.select2) {
            window.$('#appointmentVehicleSelect').trigger('change.select2');
        }
    });
}

watch(
    () => form.customer_id,
    (customerId) => {
        if (!customerId) {
            form.vehicle_id = '';
            syncVehicleSelect2();
            return;
        }
        const list = (props.vehicles ?? []).filter((v) => Number(v.customer_id) === Number(customerId));
        if (list.length === 1) {
            form.vehicle_id = list[0].id;
        } else if (!list.some((v) => Number(v.id) === Number(form.vehicle_id))) {
            form.vehicle_id = '';
        }
        syncVehicleSelect2();
    }
);

function submitAppointment() {
    if (isNewCustomer.value) {
        form.customer_id = '';
    } else {
        form.new_customer_name = '';
        form.new_customer_phone = '';
    }
    form.scheduled_at = form.channel !== 'sans_rdv' && scheduleDate.value
        ? `${scheduleDate.value} ${scheduleTime.value || '09:00'}`
        : '';
    form.post('/rendez-vous', {
        forceFormData: true,
        onSuccess: () => {
            form.reset(
                'new_customer_name',
                'new_customer_first_name',
                'new_customer_id_number',
                'new_customer_phone',
                'new_customer_profession',
                'new_customer_date_of_birth',
                'new_customer_emergency_contact',
                'new_customer_description',
                'new_customer_photo',
                'notes',
                'scheduled_at'
            );
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddAppointment'))?.hide();
        },
    });
}

const filteredAppointments = computed(() => {
    const list = props.appointments?.data ?? [];
    const today = new Date().toISOString().slice(0, 10);
    const q = searchQuery.value.trim().toLowerCase();

    return list.filter((a) => {
        if (activeFilter.value === 'aujourdhui') {
            if (!a.scheduled_at || !String(a.scheduled_at).startsWith(today)) return false;
        } else if (activeFilter.value === 'a_venir') {
            if (!a.scheduled_at || a.status === 'recu' || a.status === 'annule') return false;
        } else if (activeFilter.value === 'sans_rdv') {
            if (a.scheduled_at && a.channel !== 'sans_rdv') return false;
        } else if (activeFilter.value === 'termines') {
            if (a.status !== 'recu' && a.status !== 'annule') return false;
        }

        if (!q) return true;
        const haystack = [
            a.customer?.name,
            a.customer?.phone,
            a.vehicle?.license_plate,
            a.reason,
            a.bay_name,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return haystack.includes(q);
    });
});

async function updateStatus(id, status) {
    if (status === 'annule') {
        const ok = await confirm({
            title: 'Annuler ce rendez-vous',
            message: 'Voulez-vous vraiment annuler ce rendez-vous ? Le client ne recevra plus de rappel automatique.',
            confirmLabel: 'Oui, annuler',
            variant: 'danger',
        });
        if (!ok) return;
    }
    router.patch(`/rendez-vous/${id}/statut`, { status }, { preserveScroll: true });
}

async function openManualFollowup(appointment) {
    manualFollowupAppointment.value = appointment;
    manualFollowupForm.reset('custom_message');
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalManualFollowup'))?.show();
}

function submitManualFollowup() {
    if (!manualFollowupAppointment.value) return;
    manualFollowupForm.post(`/rendez-vous/${manualFollowupAppointment.value.id}/rappel-whatsapp`, {
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalManualFollowup'))?.hide();
        },
    });
}

function submitGenerateLink() {
    linkForm.post('/rendez-vous/liens', {
        preserveScroll: true,
        onSuccess: () => {
            linkForm.reset('label');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalGenerateAppointmentLink'))?.hide();
        },
    });
}

async function copyLink(link) {
    try {
        await navigator.clipboard.writeText(link.public_url);
        copiedLinkId.value = link.id;
        setTimeout(() => {
            if (copiedLinkId.value === link.id) copiedLinkId.value = null;
        }, 2500);
    } catch {
        window.prompt('Copiez ce lien sécurisé :', link.public_url);
    }
}

function shareUrl(network, link) {
    const text = encodeURIComponent(`Bonjour, réservez votre rendez-vous à l'atelier via ce lien sécurisé (valable 24h, usage unique) : ${link.public_url}`);
    const url = encodeURIComponent(link.public_url);
    if (network === 'whatsapp') return `https://wa.me/?text=${text}`;
    if (network === 'facebook') return `https://www.facebook.com/sharer/sharer.php?u=${url}`;
    if (network === 'x') return `https://twitter.com/intent/tweet?text=${text}`;
    if (network === 'telegram') return `https://t.me/share/url?url=${url}&text=${text}`;
    return link.public_url;
}

async function nativeShare(link) {
    if (!canNativeShare) return;
    try {
        await navigator.share({
            title: 'Lien de rendez-vous sécurisé',
            text: 'Réservez votre rendez-vous via ce lien sécurisé (valable 24h, usage unique) :',
            url: link.public_url,
        });
    } catch {
        // Ignoré si l'utilisateur annule le partage
    }
}

async function cancelLink(link) {
    const ok = await confirm({
        title: 'Désactiver ce lien sécurisé',
        message: 'Voulez-vous désactiver ce lien de rendez-vous ? Il deviendra immédiatement inutilisable.',
        confirmLabel: 'Oui, désactiver',
        variant: 'danger',
    });
    if (!ok) return;
    router.post(`/rendez-vous/liens/${link.id}/annuler`, {}, { preserveScroll: true });
}

async function openValidateModal(link) {
    inspectingLink.value = link;
    const p = link.submitted_payload || {};
    validateLinkForm.agency_id = link.agency_id || props.agencies?.[0]?.id || '';
    validateLinkForm.create_customer = true;
    validateLinkForm.customer_id = '';
    validateLinkForm.bay_name = '';
    validateLinkForm.assigned_mechanic_id = '';
    validateLinkForm.scheduled_at = p.scheduled_at ? String(p.scheduled_at).slice(0, 16).replace(' ', 'T') : '';
    validateLinkForm.duration_minutes = 60;
    validateLinkForm.notes = p.notes || '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalValidateLinkSubmission'))?.show();
}

function submitValidateLink() {
    if (!inspectingLink.value) return;
    validateLinkForm.post(`/rendez-vous/liens/${inspectingLink.value.id}/valider`, {
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalValidateLinkSubmission'))?.hide();
            inspectingLink.value = null;
        },
    });
}

function linkStatusBadge(status) {
    if (status === 'active') return { cls: 'bg-success-subtle text-success border border-success-subtle', label: 'Actif (24h)' };
    if (status === 'submitted') return { cls: 'bg-warning text-dark', label: 'À valider' };
    if (status === 'confirmed') return { cls: 'bg-primary-subtle text-primary border border-primary-subtle', label: 'Confirmé (Lien clôturé)' };
    if (status === 'expired') return { cls: 'bg-secondary-subtle text-secondary', label: 'Expiré' };
    return { cls: 'bg-danger-subtle text-danger', label: 'Désactivé' };
}
</script>

<template>
    <Head title="Rendez-vous" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Rendez-vous &amp; File d'attente</h1>
                <p class="text-muted small mb-0">Module facultatif — planification des ponts, liens sécurisés 24h et rappels WhatsApp</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalGenerateAppointmentLink">
                    <i class="bi bi-link-45deg me-1"></i>Lien sécurisé
                </button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddAppointment">
                    <i class="bi bi-calendar-plus me-1"></i>Nouveau rendez-vous
                </button>
            </div>
        </div>

        <div class="alert alert-info d-flex align-items-start gap-2 small mb-3" role="alert">
            <i class="bi bi-info-circle-fill fs-5 mt-1"></i>
            <div>
                <strong>Module facultatif :</strong> Votre garage fonctionne parfaitement sans prise de rendez-vous préalable — vous pouvez accueillir un véhicule directement depuis
                <Link href="/receptions" class="alert-link">Réceptions</Link> ou créer un <Link href="/ordres-reparation" class="alert-link">Ordre de réparation</Link>.
                Lorsqu'un rendez-vous est planifié, MboaCar envoie automatiquement un rappel WhatsApp multilingue (FR, EN, ES, AR, ZH) <strong>2 heures</strong> puis <strong>30 minutes</strong> avant l'heure prévue, sans bloquer l'application en cas d'indisponibilité de WhatsApp.
            </div>
        </div>

        <!-- Alerte demandes reçues via lien sécurisé -->
        <div v-if="submittedLinks.length" class="card border-warning shadow-sm mb-3">
            <div class="card-header bg-warning-subtle d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold small text-dark">
                    <i class="bi bi-bell-fill text-warning me-1"></i>
                    Nouvelles demandes reçues via lien sécurisé ({{ submittedLinks.length }})
                </span>
                <span class="badge bg-warning text-dark">Action requise</span>
            </div>
            <div class="list-group list-group-flush">
                <div v-for="link in submittedLinks" :key="link.id" class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                    <div class="small">
                        <div class="fw-bold">
                            {{ link.submitted_payload?.name }} {{ link.submitted_payload?.first_name ?? '' }}
                            <span class="badge bg-light text-dark border ms-1">{{ link.submitted_payload?.phone }}</span>
                        </div>
                        <div class="text-muted">
                            Motif : <strong>{{ link.submitted_payload?.reason }}</strong> •
                            Date souhaitée : <strong>{{ link.submitted_payload?.scheduled_at }}</strong>
                            <span v-if="link.submitted_payload?.vehicle_plate"> • Véhicule : <strong>{{ link.submitted_payload.vehicle_plate }}</strong></span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-warning btn-sm fw-semibold text-dark" @click="openValidateModal(link)">
                        <i class="bi bi-person-check me-1"></i>Examiner &amp; Créer compte / Valider
                    </button>
                </div>
            </div>
        </div>

        <!-- Liens sécurisés générés -->
        <div v-if="appointmentLinks.length" class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <span class="fw-bold small"><i class="bi bi-shield-lock me-1 text-primary"></i>Liens de rendez-vous sécurisés (24h / Usage unique)</span>
                <small class="text-muted">{{ appointmentLinks.length }} dernier(s) lien(s)</small>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Libellé / Agence</th>
                            <th>Statut</th>
                            <th>Expiration</th>
                            <th class="text-end">Copier / Partager</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="link in appointmentLinks" :key="link.id">
                            <td>
                                <div class="fw-semibold">{{ link.label || 'Lien de réservation client' }}</div>
                                <div class="text-muted" style="font-size: 0.75rem;">{{ link.agency?.name ?? 'Agence principale' }}</div>
                            </td>
                            <td>
                                <span class="badge" :class="linkStatusBadge(link.status).cls">{{ linkStatusBadge(link.status).label }}</span>
                            </td>
                            <td class="text-muted">{{ link.expires_at_formatted }}</td>
                            <td class="text-end text-nowrap">
                                <template v-if="link.status === 'active'">
                                    <button type="button" class="btn btn-outline-primary btn-sm me-1" @click="copyLink(link)">
                                        <i class="bi" :class="copiedLinkId === link.id ? 'bi-check2' : 'bi-clipboard'"></i>
                                        {{ copiedLinkId === link.id ? 'Copié' : 'Copier' }}
                                    </button>
                                    <a :href="shareUrl('whatsapp', link)" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm me-1" title="Partager sur WhatsApp">
                                        <i class="bi bi-whatsapp"></i>
                                    </a>
                                    <a :href="shareUrl('facebook', link)" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm me-1" title="Partager sur Facebook">
                                        <i class="bi bi-facebook"></i>
                                    </a>
                                    <a :href="shareUrl('x', link)" target="_blank" rel="noopener" class="btn btn-outline-dark btn-sm me-1" title="Partager sur X">
                                        <i class="bi bi-twitter-x"></i>
                                    </a>
                                    <a :href="shareUrl('telegram', link)" target="_blank" rel="noopener" class="btn btn-outline-info btn-sm me-1" title="Partager sur Telegram">
                                        <i class="bi bi-telegram"></i>
                                    </a>
                                    <button v-if="canNativeShare" type="button" class="btn btn-outline-secondary btn-sm me-1" title="Partager" @click="nativeShare(link)">
                                        <i class="bi bi-share"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" title="Désactiver le lien" @click="cancelLink(link)">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </template>
                                <button v-else-if="link.status === 'submitted'" type="button" class="btn btn-warning btn-sm text-dark fw-semibold" @click="openValidateModal(link)">
                                    Valider
                                </button>
                                <span v-else class="text-muted fst-italic">Lien clôturé</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn" :class="activeFilter === 'tous' ? 'btn-primary' : 'btn-outline-secondary'" @click="activeFilter = 'tous'">Tous</button>
                <button type="button" class="btn" :class="activeFilter === 'aujourdhui' ? 'btn-primary' : 'btn-outline-secondary'" @click="activeFilter = 'aujourdhui'">Aujourd'hui</button>
                <button type="button" class="btn" :class="activeFilter === 'a_venir' ? 'btn-primary' : 'btn-outline-secondary'" @click="activeFilter = 'a_venir'">À venir</button>
                <button type="button" class="btn" :class="activeFilter === 'sans_rdv' ? 'btn-primary' : 'btn-outline-secondary'" @click="activeFilter = 'sans_rdv'">File sans RDV</button>
                <button type="button" class="btn" :class="activeFilter === 'termines' ? 'btn-primary' : 'btn-outline-secondary'" @click="activeFilter = 'termines'">Terminés</button>
            </div>
            <div class="input-group input-group-sm" style="max-width: 280px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input v-model="searchQuery" type="text" class="form-control" placeholder="Rechercher client, plaque, pont..." />
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Client / Véhicule</th>
                                <th>Canal &amp; Pont</th>
                                <th>Motif</th>
                                <th>Date prévue</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!filteredAppointments.length">
                                <td colspan="6" class="text-center text-muted py-4 small">Aucun rendez-vous correspondant.</td>
                            </tr>
                            <tr v-for="a in filteredAppointments" :key="a.id">
                                <td>
                                    <div class="fw-semibold">{{ a.customer?.name }}</div>
                                    <div class="small text-muted">{{ a.vehicle?.license_plate ?? 'Véhicule à préciser' }}</div>
                                </td>
                                <td class="small">
                                    <div>{{ a.channel }}</div>
                                    <span class="text-muted">{{ a.bay_name ?? '-' }}</span>
                                </td>
                                <td>{{ a.reason }}</td>
                                <td class="small">
                                    <div>{{ a.scheduled_at ?? 'File sans RDV' }}</div>
                                    <div class="d-flex gap-1 mt-1" v-if="a.reminder_2h_sent_at || a.reminder_30m_sent_at">
                                        <span v-if="a.reminder_2h_sent_at" class="badge bg-success-subtle text-success border border-success-subtle">Rappel 2h ✓</span>
                                        <span v-if="a.reminder_30m_sent_at" class="badge bg-success-subtle text-success border border-success-subtle">Rappel 30m ✓</span>
                                    </div>
                                </td>
                                <td><span class="badge bg-info text-dark">{{ a.status }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button
                                        v-if="a.customer?.phone"
                                        type="button"
                                        class="btn btn-outline-success btn-sm me-1"
                                        title="Relancer manuellement sur WhatsApp"
                                        @click="openManualFollowup(a)"
                                    >
                                        <i class="bi bi-whatsapp me-1"></i>Relancer
                                    </button>
                                    <Link href="/receptions" class="btn btn-outline-primary btn-sm me-1">Réception</Link>
                                    <button v-if="a.status !== 'recu' && a.status !== 'annule'" type="button" class="btn btn-outline-success btn-sm me-1" @click="updateStatus(a.id, 'recu')">Reçu</button>
                                    <button v-if="a.status !== 'annule' && a.status !== 'recu'" type="button" class="btn btn-outline-danger btn-sm" @click="updateStatus(a.id, 'annule')">Annuler</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddAppointment" title="Nouveau rendez-vous" size="modal-lg">
            <form @submit.prevent="submitAppointment">
                <div class="form-check form-switch mb-3">
                    <input id="newCustModal" v-model="isNewCustomer" type="checkbox" class="form-check-input" />
                    <label for="newCustModal" class="form-check-label small fw-semibold">Nouveau client (création automatique)</label>
                </div>

                <div v-if="!isNewCustomer" class="mb-3">
                    <label class="form-label small">Client</label>
                    <select v-model="form.customer_id" class="form-select" required>
                        <option value="">-- Choisir un client --</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.phone }})</option>
                    </select>
                </div>

                <div v-else class="border rounded p-3 mb-3 bg-light">
                    <div class="row g-2 mb-2">
                        <div class="col-md-5">
                            <label class="form-label small">Nom du client <span class="text-danger">*</span></label>
                            <input v-model="form.new_customer_name" type="text" class="form-control form-control-sm" required />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Prénom (facultatif)</label>
                            <input v-model="form.new_customer_first_name" type="text" class="form-control form-control-sm" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Sexe <span class="text-danger">*</span></label>
                            <select v-model="form.new_customer_gender" class="form-select form-select-sm" required>
                                <option value="homme">Homme</option>
                                <option value="femme">Femme</option>
                                <option value="autre">Autre</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label small">N° CNI ou Passeport <span class="text-danger">*</span></label>
                            <input v-model="form.new_customer_id_number" type="text" class="form-control form-control-sm" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Téléphone WhatsApp <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm">
                                <select v-model="form.new_customer_dial_code" class="form-select" style="max-width: 100px;">
                                    <option :value="defaultDialCode">{{ defaultDialCode }}</option>
                                    <option v-for="c in countries" :key="c.code" :value="c.dial_code">{{ c.code }} ({{ c.dial_code }})</option>
                                </select>
                                <input v-model="form.new_customer_phone" type="tel" class="form-control" required />
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label small">Profession (facultatif)</label>
                            <input v-model="form.new_customer_profession" type="text" class="form-control form-control-sm" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Date de naissance (facultatif)</label>
                            <DatePickerInput v-model="form.new_customer_date_of_birth" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Contact d'urgence (facultatif)</label>
                            <input v-model="form.new_customer_emergency_contact" type="text" class="form-control form-control-sm" />
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Description (facultatif)</label>
                        <textarea v-model="form.new_customer_description" rows="2" class="form-control form-control-sm"></textarea>
                    </div>
                    <div>
                        <label class="form-label small">Photo de profil (facultatif)</label>
                        <FileDropzone v-model="form.new_customer_photo" label="Glissez-déposez la photo ou cliquez" />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Agence</label>
                        <select v-model="form.agency_id" class="form-select" required>
                            <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Véhicule</label>
                        <select id="appointmentVehicleSelect" v-model="form.vehicle_id" class="form-select">
                            <option value="">-- Optionnel --</option>
                            <option v-for="v in filteredVehicles" :key="v.id" :value="v.id">{{ v.license_plate }}</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Canal</label>
                        <select v-model="form.channel" class="form-select">
                            <option value="telephone">Téléphone</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="en_ligne">En ligne</option>
                            <option value="sans_rdv">Sans RDV (File)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Motif</label>
                        <select v-model="form.reason" class="form-select">
                            <option value="entretien">Entretien</option>
                            <option value="panne">Panne</option>
                            <option value="controle">Contrôle</option>
                            <option value="accident">Accident</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Pont / Baie</label>
                        <input v-model="form.bay_name" type="text" class="form-control" />
                    </div>
                </div>

                <div v-if="form.channel !== 'sans_rdv'" class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label small">Date prévue</label>
                        <DatePickerInput v-model="scheduleDate" required />
                    </div>
                    <div class="col-5">
                        <label class="form-label small">Heure</label>
                        <TimePickerInput v-model="scheduleTime" required />
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small">Notes</label>
                    <textarea v-model="form.notes" rows="2" class="form-control"></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal Génération Lien Sécurisé -->
        <StaticModal id="modalGenerateAppointmentLink" title="Générer un lien de rendez-vous sécurisé">
            <form @submit.prevent="submitGenerateLink">
                <p class="small text-muted">
                    Le lien généré est <strong>unique</strong>, <strong>sécurisé</strong>, valable <strong>24 heures</strong> et devient automatiquement inutilisable dès que la demande est soumise puis confirmée.
                </p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Agence</label>
                    <select v-model="linkForm.agency_id" class="form-select" required>
                        <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Libellé / Destinataire (facultatif)</label>
                    <input v-model="linkForm.label" type="text" class="form-control" placeholder="Ex: M. Kamga / Partage WhatsApp" />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="linkForm.processing">
                        <i class="bi bi-shield-lock me-1"></i>Générer le lien (24h)
                    </button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal Validation Demande reçue via Lien Sécurisé -->
        <StaticModal id="modalValidateLinkSubmission" title="Valider la demande &amp; Créer le compte client" size="modal-lg">
            <form v-if="inspectingLink" @submit.prevent="submitValidateLink">
                <div class="border rounded p-3 mb-3 bg-light small">
                    <div class="fw-bold text-primary mb-2">Informations saisies par le client</div>
                    <div class="row g-2">
                        <div class="col-md-6"><strong>Nom :</strong> {{ inspectingLink.submitted_payload?.name }} {{ inspectingLink.submitted_payload?.first_name ?? '' }}</div>
                        <div class="col-md-6"><strong>Sexe :</strong> {{ inspectingLink.submitted_payload?.gender }}</div>
                        <div class="col-md-6"><strong>CNI / Passeport :</strong> {{ inspectingLink.submitted_payload?.id_number }}</div>
                        <div class="col-md-6"><strong>WhatsApp :</strong> {{ inspectingLink.submitted_payload?.phone }}</div>
                        <div v-if="inspectingLink.submitted_payload?.profession" class="col-md-6"><strong>Profession :</strong> {{ inspectingLink.submitted_payload.profession }}</div>
                        <div v-if="inspectingLink.submitted_payload?.emergency_contact" class="col-md-6"><strong>Urgence :</strong> {{ inspectingLink.submitted_payload.emergency_contact }}</div>
                        <div v-if="inspectingLink.submitted_payload?.vehicle_plate" class="col-md-12">
                            <strong>Véhicule :</strong> {{ inspectingLink.submitted_payload.vehicle_plate }}
                            ({{ inspectingLink.submitted_payload.vehicle_brand ?? '-' }} {{ inspectingLink.submitted_payload.vehicle_model ?? '' }})
                        </div>
                        <div class="col-md-6"><strong>Motif :</strong> {{ inspectingLink.submitted_payload?.reason }}</div>
                        <div class="col-md-6"><strong>Date souhaitée :</strong> {{ inspectingLink.submitted_payload?.scheduled_at }}</div>
                        <div v-if="inspectingLink.submitted_payload?.description" class="col-12"><strong>Description :</strong> {{ inspectingLink.submitted_payload.description }}</div>
                        <div v-if="inspectingLink.submitted_payload?.notes" class="col-12"><strong>Notes :</strong> {{ inspectingLink.submitted_payload.notes }}</div>
                    </div>
                </div>

                <div class="form-check form-switch mb-3">
                    <input id="chkCreateCustomerFromLink" v-model="validateLinkForm.create_customer" type="checkbox" class="form-check-input" />
                    <label for="chkCreateCustomerFromLink" class="form-check-label small fw-semibold">
                        Créer automatiquement la fiche / compte client (et le véhicule) à partir de ces informations
                    </label>
                </div>

                <div v-if="!validateLinkForm.create_customer" class="mb-3">
                    <label class="form-label small fw-semibold">Ou associer à un client existant</label>
                    <select v-model="validateLinkForm.customer_id" class="form-select" required>
                        <option value="">-- Choisir un client --</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.phone }})</option>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Agence</label>
                        <select v-model="validateLinkForm.agency_id" class="form-select" required>
                            <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Date &amp; Heure confirmées</label>
                        <input v-model="validateLinkForm.scheduled_at" type="datetime-local" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Pont / Baie</label>
                        <input v-model="validateLinkForm.bay_name" type="text" class="form-control" placeholder="Ex: Pont 1" />
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Notes de confirmation</label>
                    <textarea v-model="validateLinkForm.notes" rows="2" class="form-control"></textarea>
                </div>

                <div class="alert alert-success py-2 small mb-3">
                    <i class="bi bi-whatsapp me-1"></i>
                    Dès validation, un message WhatsApp de confirmation sera envoyé au client et ce lien sera définitivement clôturé.
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="submit" class="btn btn-success px-4" :disabled="validateLinkForm.processing">
                        <i class="bi bi-check-circle me-1"></i>Confirmer le rendez-vous
                    </button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal Relance Manuelle WhatsApp -->
        <StaticModal id="modalManualFollowup" title="Relancer le client sur WhatsApp">
            <form v-if="manualFollowupAppointment" @submit.prevent="submitManualFollowup">
                <p class="small mb-2">
                    Destinataire : <strong>{{ manualFollowupAppointment.customer?.name }}</strong> ({{ manualFollowupAppointment.customer?.phone }})
                </p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Message personnalisé (facultatif)</label>
                    <textarea
                        v-model="manualFollowupForm.custom_message"
                        rows="3"
                        class="form-control"
                        placeholder="Laissez vide pour envoyer le rappel automatique standard dans la langue du site..."
                    ></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success px-4" :disabled="manualFollowupForm.processing">
                        <i class="bi bi-whatsapp me-1"></i>Envoyer la relance
                    </button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
