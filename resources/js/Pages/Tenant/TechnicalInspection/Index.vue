<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    pvs: { type: Array, default: () => [] },
    agencies: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();

const defaultDefects = () => [
    { category: 'Freinage', item: 'Efficacité frein de service & stationnement', severity: 'ok', note: '' },
    { category: 'Éclairage', item: 'Feux de croisement, route, stop & clignotants', severity: 'ok', note: '' },
    { category: 'Direction', item: 'Jeu de direction, rotules & parallélisme', severity: 'ok', note: '' },
    { category: 'Liaison au sol', item: 'Amortisseurs, essieux & pneumatiques', severity: 'ok', note: '' },
    { category: 'Pollution', item: 'Opacité fumées / Émissions échappement', severity: 'ok', note: '' },
    { category: 'Châssis', item: 'Identification châssis, ceintures & rétroviseurs', severity: 'ok', note: '' },
];

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    vehicle_id: '',
    inspection_line: 'Ligne 1',
    mileage: '',
    verdict: 'favorable',
    defects: defaultDefects(),
    expires_at: '',
    counter_visit_deadline: '',
    notes: '',
});

function submitPv() {
    form.post('/visite-technique', {
        onSuccess: () => {
            form.reset('vehicle_id', 'mileage', 'expires_at', 'counter_visit_deadline', 'notes');
            form.verdict = 'favorable';
            form.defects = defaultDefects();
            window.bootstrap?.Modal.getInstance(document.getElementById('modalNewPv'))?.hide();
        },
    });
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
                <p class="text-muted small mb-0">Procès-verbaux officiels, contre-visites, QR de vérification et envoi WhatsApp</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNewPv">
                <i class="bi bi-clipboard2-check me-1"></i>Nouveau PV Visite Technique
            </button>
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
                            <th>Validité / Contre-visite</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!pvs.length">
                            <td colspan="6" class="text-center text-muted py-4">Aucun procès-verbal enregistré.</td>
                        </tr>
                        <tr v-for="pv in pvs" :key="pv.id">
                            <td>
                                <div class="fw-bold">{{ pv.pv_number }}</div>
                                <div class="text-muted">{{ pv.inspected_at }}</div>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ pv.vehicle?.license_plate }} <span class="text-muted fw-normal">{{ pv.vehicle?.brand }} {{ pv.vehicle?.model }}</span></div>
                                <div class="text-muted">{{ pv.customer?.name ?? '-' }}</div>
                            </td>
                            <td>
                                <div>{{ pv.inspection_line || 'Ligne 1' }}</div>
                                <div class="text-muted">{{ pv.mileage ? `${pv.mileage} km` : '-' }}</div>
                            </td>
                            <td>
                                <span class="badge" :class="verdictBadge(pv.verdict).cls">{{ verdictBadge(pv.verdict).label }}</span>
                            </td>
                            <td>
                                <div v-if="pv.expires_at">Valable : <strong>{{ pv.expires_at }}</strong></div>
                                <div v-if="pv.counter_visit_deadline" class="text-warning-emphasis">Limite CV : {{ pv.counter_visit_deadline }}</div>
                            </td>
                            <td class="text-end text-nowrap">
                                <a :href="pv.public_url" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm me-1">
                                    <i class="bi bi-qr-code me-1"></i>Voir PV
                                </a>
                                <button v-if="pv.customer?.phone" type="button" class="btn btn-outline-success btn-sm" @click="sendWhatsapp(pv)">
                                    <i class="bi bi-whatsapp me-1"></i>WhatsApp
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalNewPv" title="Établir un Procès-Verbal de Visite Technique" size="modal-lg">
            <form @submit.prevent="submitPv">
                <div class="row g-2 mb-3">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Véhicule <span class="text-danger">*</span></label>
                        <select v-model="form.vehicle_id" class="form-select" required>
                            <option value="">-- Choisir un véhicule --</option>
                            <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.license_plate }} — {{ v.brand }} {{ v.model }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Verdict <span class="text-danger">*</span></label>
                        <select v-model="form.verdict" class="form-select" required>
                            <option value="favorable">Favorable (Apte)</option>
                            <option value="contre_visite">Contre-visite requise</option>
                            <option value="immobilisation">Immobilisation (Critique)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Kilométrage</label>
                        <input v-model="form.mileage" type="number" min="0" class="form-control" />
                    </div>
                </div>

                <div class="row g-2 mb-3">
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

                <div class="border rounded p-2 mb-3 bg-light">
                    <div class="small fw-bold mb-2">Points de contrôle réglementaires</div>
                    <div v-for="(row, idx) in form.defects" :key="idx" class="row g-2 align-items-center mb-2 pb-2 border-bottom">
                        <div class="col-md-4 small">
                            <div class="fw-semibold">{{ row.item }}</div>
                            <div class="text-muted" style="font-size: 0.72rem;">{{ row.category }}</div>
                        </div>
                        <div class="col-md-5">
                            <div class="btn-group btn-group-sm w-100">
                                <button type="button" class="btn" :class="row.severity === 'ok' ? 'btn-success' : 'btn-outline-success'" @click="row.severity = 'ok'">OK</button>
                                <button type="button" class="btn" :class="row.severity === 'mineur' ? 'btn-info text-dark' : 'btn-outline-info'" @click="row.severity = 'mineur'">Mineur</button>
                                <button type="button" class="btn" :class="row.severity === 'majeur' ? 'btn-warning text-dark' : 'btn-outline-warning'" @click="row.severity = 'majeur'">Majeur</button>
                                <button type="button" class="btn" :class="row.severity === 'critique' ? 'btn-danger' : 'btn-outline-danger'" @click="row.severity = 'critique'">Critique</button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <input v-model="row.note" type="text" class="form-control form-control-sm" placeholder="Observation..." />
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold">Observations générales</label>
                    <textarea v-model="form.notes" rows="2" class="form-control"></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Enregistrer le PV</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
