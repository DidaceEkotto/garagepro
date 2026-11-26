<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    pvs: { type: Object, required: true },
    filters: { type: Object, default: () => ({ q: '', verdict: 'all' }) },
    openCounterVisits: { type: Array, default: () => [] },
    agencies: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();

const pvList = computed(() => (Array.isArray(props.pvs) ? props.pvs : (props.pvs?.data ?? [])));
const pvLinks = computed(() => (!Array.isArray(props.pvs) && props.pvs?.links ? props.pvs.links : []));

const searchQuery = ref(props.filters?.q || '');
const verdictFilter = ref(props.filters?.verdict || 'all');

function applyFilters() {
    router.get('/visite-technique', {
        q: searchQuery.value || undefined,
        verdict: verdictFilter.value !== 'all' ? verdictFilter.value : undefined,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

const defaultDefects = () => [
    { category: 'Freinage', item: 'Efficacité frein de service & stationnement', severity: 'ok', note: '' },
    { category: 'Éclairage', item: 'Feux de croisement, route, stop & clignotants', severity: 'ok', note: '' },
    { category: 'Direction', item: 'Jeu de direction, rotules & parallélisme', severity: 'ok', note: '' },
    { category: 'Liaison au sol', item: 'Amortisseurs, essieux & pneumatiques', severity: 'ok', note: '' },
    { category: 'Pollution', item: 'Opacité fumées / Émissions échappement', severity: 'ok', note: '' },
    { category: 'Châssis', item: 'Identification châssis, ceintures & rétroviseurs', severity: 'ok', note: '' },
];

const editingPv = ref(null);
const qrModalPv = ref(null);
const qrDataUrl = ref('');

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    parent_inspection_id: '',
    vehicle_id: '',
    inspection_line: 'Ligne 1',
    mileage: '',
    verdict: 'favorable',
    defects: defaultDefects(),
    expires_at: '',
    counter_visit_deadline: '',
    notes: '',
});

const matchingCounterVisits = computed(() => {
    if (!form.vehicle_id) return props.openCounterVisits;
    return props.openCounterVisits.filter((cv) => Number(cv.vehicle_id) === Number(form.vehicle_id));
});

// Pré-remplir le kilométrage au choix d'un véhicule (en création)
watch(() => form.vehicle_id, (newVal) => {
    if (editingPv.value || !newVal) return;
    const v = props.vehicles.find((item) => Number(item.id) === Number(newVal));
    if (v && v.mileage) {
        form.mileage = v.mileage;
    }
});

function setDefectSeverity(row, severity) {
    row.severity = severity;
    autoSuggestVerdict();
}

function autoSuggestVerdict() {
    const severities = form.defects.map((d) => d.severity);
    if (severities.includes('critique')) {
        form.verdict = 'immobilisation';
    } else if (severities.includes('majeur')) {
        form.verdict = 'contre_visite';
    } else {
        form.verdict = 'favorable';
    }
}

function addDefectRow() {
    form.defects.push({ category: 'Autre', item: '', severity: 'ok', note: '' });
}

function removeDefectRow(idx) {
    if (form.defects.length <= 1) return;
    form.defects.splice(idx, 1);
    autoSuggestVerdict();
}

async function openNewPvModal() {
    editingPv.value = null;
    form.clearErrors();
    form.reset();
    form.agency_id = props.agencies?.[0]?.id ?? '';
    form.parent_inspection_id = '';
    form.inspection_line = 'Ligne 1';
    form.verdict = 'favorable';
    form.defects = defaultDefects();
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalNewPv'))?.show();
}

async function openEditPvModal(pv) {
    editingPv.value = pv;
    form.clearErrors();
    form.agency_id = pv.agency_id ?? (props.agencies?.[0]?.id ?? '');
    form.parent_inspection_id = pv.parent_inspection_id ?? '';
    form.vehicle_id = pv.vehicle_id ?? '';
    form.inspection_line = pv.inspection_line || 'Ligne 1';
    form.mileage = pv.mileage ?? '';
    form.verdict = pv.verdict || 'favorable';
    form.defects = Array.isArray(pv.defects) && pv.defects.length
        ? pv.defects.map((d) => ({ category: d.category || '', item: d.item || '', severity: d.severity || 'ok', note: d.note || '' }))
        : defaultDefects();
    form.expires_at = pv.expires_at_iso || '';
    form.counter_visit_deadline = pv.counter_visit_deadline_iso || '';
    form.notes = pv.notes || '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalNewPv'))?.show();
}

function submitPv() {
    if (editingPv.value) {
        form.put(`/visite-technique/${editingPv.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editingPv.value = null;
                window.bootstrap?.Modal.getInstance(document.getElementById('modalNewPv'))?.hide();
            },
        });
        return;
    }

    form.post('/visite-technique', {
        onSuccess: () => {
            form.reset('vehicle_id', 'parent_inspection_id', 'mileage', 'expires_at', 'counter_visit_deadline', 'notes');
            form.verdict = 'favorable';
            form.defects = defaultDefects();
            window.bootstrap?.Modal.getInstance(document.getElementById('modalNewPv'))?.hide();
        },
    });
}

async function openQrModal(pv) {
    qrModalPv.value = pv;
    qrDataUrl.value = '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalPvQr'))?.show();
    try {
        qrDataUrl.value = await QRCode.toDataURL(pv.public_url, {
            width: 240,
            margin: 2,
            color: { dark: '#0f172a', light: '#ffffff' },
        });
    } catch {
        qrDataUrl.value = '';
    }
}

async function sendWhatsapp(pv) {
    const ok = await confirm({
        title: 'Envoyer le PV sur WhatsApp',
        message: `Envoyer le procès-verbal ${pv.pv_number} (${pv.vehicle?.license_plate ?? ''}) à ${pv.customer?.name ?? 'ce client'} ?`,
        confirmLabel: 'Envoyer sur WhatsApp',
        variant: 'success',
    });
    if (!ok) return;
    router.post(`/visite-technique/${pv.id}/whatsapp`, {}, { preserveScroll: true });
}

function verdictBadge(verdict) {
    if (verdict === 'favorable') return { cls: 'bg-success', label: 'Favorable (Apte)' };
    if (verdict === 'contre_visite') return { cls: 'bg-warning text-dark', label: 'Contre-visite' };
    return { cls: 'bg-danger', label: 'Immobilisation' };
}
</script>

<template>
    <Head title="Visite Technique &amp; PV" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Centre de Contrôle &amp; Visite Technique</h1>
                <p class="text-muted small mb-0">Procès-verbaux officiels, suivi contre-visites, QR de vérification, PDF et envoi WhatsApp</p>
            </div>
            <button type="button" class="btn btn-primary" @click="openNewPvModal">
                <i class="bi bi-clipboard2-check me-1"></i>Nouveau PV Visite Technique
            </button>
        </div>

        <!-- Recherche & filtre par verdict -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2 px-3">
                <form class="row g-2 align-items-center" @submit.prevent="applyFilters">
                    <div class="col-md-5">
                        <input
                            v-model="searchQuery"
                            type="text"
                            class="form-control form-control-sm"
                            placeholder="Rechercher par N° PV, plaque, marque, modèle ou client..."
                        />
                    </div>
                    <div class="col-md-3">
                        <select v-model="verdictFilter" class="form-select form-select-sm" @change="applyFilters">
                            <option value="all">Tous les verdicts</option>
                            <option value="favorable">Favorable (Apte)</option>
                            <option value="contre_visite">Contre-visite</option>
                            <option value="immobilisation">Immobilisation</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                            <i class="bi bi-search me-1"></i>Filtrer
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>N° PV / Date</th>
                            <th>Véhicule / Client</th>
                            <th>Ligne / Km</th>
                            <th>Verdict</th>
                            <th>Validité / WhatsApp</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!pvList.length">
                            <td colspan="6" class="text-center text-muted py-4">Aucun procès-verbal enregistré.</td>
                        </tr>
                        <tr v-for="pv in pvList" :key="pv.id">
                            <td>
                                <div class="fw-bold">{{ pv.pv_number }}</div>
                                <div class="text-muted">{{ pv.inspected_at }}</div>
                                <span v-if="pv.parent_pv_number" class="badge bg-info-subtle text-dark border" style="font-size: 0.68rem;">
                                    Contre-visite de {{ pv.parent_pv_number }}
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ pv.vehicle?.license_plate }} <span class="text-muted fw-normal">{{ pv.vehicle?.brand }} {{ pv.vehicle?.model }}</span></div>
                                <div class="text-muted">{{ pv.customer?.name ?? '-' }}</div>
                            </td>
                            <td>
                                <div>{{ pv.inspection_line || 'Ligne 1' }}</div>
                                <div class="text-muted">{{ pv.mileage ? `${Number(pv.mileage).toLocaleString('fr-FR')} km` : '-' }}</div>
                            </td>
                            <td>
                                <span class="badge" :class="verdictBadge(pv.verdict).cls">{{ verdictBadge(pv.verdict).label }}</span>
                            </td>
                            <td>
                                <div v-if="pv.expires_at">Valable : <strong>{{ pv.expires_at }}</strong></div>
                                <div v-if="pv.counter_visit_deadline" class="text-warning-emphasis">Limite CV : {{ pv.counter_visit_deadline }}</div>
                                <div v-if="pv.whatsapp_sent_at" class="text-success" style="font-size: 0.72rem;">
                                    <i class="bi bi-whatsapp me-1"></i>Envoyé le {{ pv.whatsapp_sent_at }}
                                </div>
                            </td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-outline-dark btn-sm me-1" title="Afficher le QR Code" @click="openQrModal(pv)">
                                    <i class="bi bi-qr-code"></i>
                                </button>
                                <a :href="pv.pdf_url" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm me-1" title="Télécharger le PV PDF">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </a>
                                <a :href="pv.public_url" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm me-1">
                                    Voir PV
                                </a>
                                <button type="button" class="btn btn-outline-info btn-sm me-1" title="Modifier le PV" @click="openEditPvModal(pv)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button
                                    v-if="pv.customer?.whatsapp_phone || pv.customer?.phone"
                                    type="button"
                                    class="btn btn-outline-success btn-sm"
                                    @click="sendWhatsapp(pv)"
                                >
                                    <i class="bi bi-whatsapp me-1"></i>WhatsApp
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="pvLinks.length > 3" class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
                <div class="small text-muted">Total : {{ pvs.total }} procès-verbaux</div>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li v-for="(link, idx) in pvLinks" :key="idx" class="page-item" :class="{ active: link.active, disabled: !link.url }">
                            <Link v-if="link.url" :href="link.url" class="page-link" preserve-scroll>{{ link.label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/<[^>]*>/g, '') }}</Link>
                            <span v-else class="page-link">{{ link.label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/<[^>]*>/g, '') }}</span>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>

        <!-- Modal Nouveau / Éditer PV -->
        <StaticModal id="modalNewPv" :title="editingPv ? `Modifier le PV ${editingPv.pv_number}` : 'Établir un Procès-Verbal de Visite Technique'" size="modal-lg">
            <form @submit.prevent="submitPv">
                <div v-if="Object.keys(form.errors).length" class="alert alert-danger py-2 small mb-3">
                    <div v-for="(err, field) in form.errors" :key="field">{{ err }}</div>
                </div>

                <div class="row g-2 mb-3">
                    <div v-if="!editingPv" class="col-md-5">
                        <label class="form-label small fw-semibold">Véhicule <span class="text-danger">*</span></label>
                        <select v-model="form.vehicle_id" class="form-select" required>
                            <option value="">-- Choisir un véhicule --</option>
                            <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.license_plate }} — {{ v.brand }} {{ v.model }}</option>
                        </select>
                    </div>
                    <div :class="editingPv ? 'col-md-6' : 'col-md-4'">
                        <label class="form-label small fw-semibold">Verdict <span class="text-danger">*</span></label>
                        <select v-model="form.verdict" class="form-select" required>
                            <option value="favorable">Favorable (Apte)</option>
                            <option value="contre_visite">Contre-visite requise</option>
                            <option value="immobilisation">Immobilisation (Critique)</option>
                        </select>
                    </div>
                    <div :class="editingPv ? 'col-md-6' : 'col-md-3'">
                        <label class="form-label small fw-semibold">Kilométrage</label>
                        <input v-model="form.mileage" type="number" min="0" class="form-control" />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div v-if="agencies.length > 1" class="col-md-4">
                        <label class="form-label small fw-semibold">Agence / Centre</label>
                        <select v-model="form.agency_id" class="form-select">
                            <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Ligne de contrôle</label>
                        <input v-model="form.inspection_line" type="text" class="form-control" />
                    </div>
                    <div v-if="form.verdict === 'favorable'" class="col-md-4">
                        <label class="form-label small fw-semibold">Date d'expiration (défaut +1 an)</label>
                        <DatePickerInput v-model="form.expires_at" />
                    </div>
                    <div v-if="form.verdict === 'contre_visite'" class="col-md-4">
                        <label class="form-label small fw-semibold">Date limite contre-visite</label>
                        <DatePickerInput v-model="form.counter_visit_deadline" />
                    </div>
                </div>

                <div v-if="!editingPv && matchingCounterVisits.length" class="mb-3">
                    <label class="form-label small fw-semibold">Contre-visite liée à un PV précédent (facultatif)</label>
                    <select v-model="form.parent_inspection_id" class="form-select form-select-sm">
                        <option value="">-- Contrôle initial (aucun PV parent) --</option>
                        <option v-for="cv in matchingCounterVisits" :key="cv.id" :value="cv.id">
                            {{ cv.pv_number }} — {{ cv.license_plate }} {{ cv.deadline ? `(limite ${cv.deadline})` : '' }}
                        </option>
                    </select>
                </div>

                <div class="border rounded p-2 mb-3 bg-light">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="small fw-bold">Points de contrôle réglementaires</div>
                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" @click="addDefectRow">
                            <i class="bi bi-plus-lg me-1"></i>Ajouter un point
                        </button>
                    </div>
                    <div v-for="(row, idx) in form.defects" :key="idx" class="row g-2 align-items-center mb-2 pb-2 border-bottom">
                        <div class="col-md-4 small">
                            <input v-model="row.item" type="text" class="form-control form-control-sm fw-semibold mb-1" placeholder="Point de contrôle" required />
                            <input v-model="row.category" type="text" class="form-control form-control-sm text-muted" style="font-size: 0.72rem;" placeholder="Catégorie" required />
                        </div>
                        <div class="col-md-5">
                            <div class="btn-group btn-group-sm w-100">
                                <button type="button" class="btn" :class="row.severity === 'ok' ? 'btn-success' : 'btn-outline-success'" @click="setDefectSeverity(row, 'ok')">OK</button>
                                <button type="button" class="btn" :class="row.severity === 'mineur' ? 'btn-info text-dark' : 'btn-outline-info'" @click="setDefectSeverity(row, 'mineur')">Mineur</button>
                                <button type="button" class="btn" :class="row.severity === 'majeur' ? 'btn-warning text-dark' : 'btn-outline-warning'" @click="setDefectSeverity(row, 'majeur')">Majeur</button>
                                <button type="button" class="btn" :class="row.severity === 'critique' ? 'btn-danger' : 'btn-outline-danger'" @click="setDefectSeverity(row, 'critique')">Critique</button>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex gap-1">
                            <input v-model="row.note" type="text" class="form-control form-control-sm" placeholder="Observation..." />
                            <button v-if="form.defects.length > 1" type="button" class="btn btn-outline-danger btn-sm px-1" title="Retirer" @click="removeDefectRow(idx)">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold">Observations générales</label>
                    <textarea v-model="form.notes" rows="2" class="form-control"></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">
                        {{ editingPv ? 'Mettre à jour le PV' : 'Enregistrer le PV' }}
                    </button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal QR Code officiel -->
        <StaticModal id="modalPvQr" title="QR Code de vérification officielle" size="modal-sm">
            <div v-if="qrModalPv" class="text-center">
                <div class="fw-bold mb-1">{{ qrModalPv.pv_number }}</div>
                <div class="small text-muted mb-3">{{ qrModalPv.vehicle?.license_plate }}</div>
                <div class="bg-white border rounded-3 p-3 d-inline-block mb-3">
                    <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR Code PV" style="width: 200px; height: 200px;" />
                    <div v-else class="text-muted small py-5">Génération du QR Code...</div>
                </div>
                <div class="d-grid gap-2">
                    <a v-if="qrDataUrl" :href="qrDataUrl" :download="`qr-${qrModalPv.pv_number}.png`" class="btn btn-primary btn-sm">
                        <i class="bi bi-download me-1"></i>Télécharger le QR (PNG)
                    </a>
                    <a :href="qrModalPv.pdf_url" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le PV PDF
                    </a>
                </div>
            </div>
        </StaticModal>
    </TenantLayout>
</template>
