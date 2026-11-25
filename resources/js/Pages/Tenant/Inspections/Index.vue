<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useLightbox } from '@/composables/useLightbox';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    inspections: { type: Array, default: () => [] },
    agencies: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    repairOrders: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const { openLightbox } = useLightbox();

const defaultChecklist = () => [
    { category: 'Freinage', item: 'Plaquettes & Disques avant/arrière', state: 'ok', note: '' },
    { category: 'Pneumatiques', item: 'Usure & Pression des 4 pneus', state: 'ok', note: '' },
    { category: 'Énergie', item: 'Batterie & Alternateur', state: 'ok', note: '' },
    { category: 'Moteur', item: 'Niveaux huile, refroidissement & freins', state: 'ok', note: '' },
    { category: 'Liaison au sol', item: 'Amortisseurs, rotules & suspension', state: 'ok', note: '' },
    { category: 'Visibilité', item: 'Éclairage, essuie-glaces & pare-brise', state: 'ok', note: '' },
    { category: 'Moteur', item: 'Courroies, durites & étanchéité', state: 'ok', note: '' },
    { category: 'Carrosserie', item: 'État général extérieur & châssis', state: 'ok', note: '' },
];

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    vehicle_id: '',
    repair_order_id: '',
    checklist: defaultChecklist(),
    photos: [],
    summary_notes: '',
});

function submitInspection() {
    form.post('/inspections', {
        forceFormData: true,
        onSuccess: () => {
            form.reset('vehicle_id', 'repair_order_id', 'photos', 'summary_notes');
            form.checklist = defaultChecklist();
            window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalNewInspection'))?.hide();
        },
    });
}

async function sendWhatsapp(insp) {
    const ok = await confirm({
        title: 'Envoyer le rapport sur WhatsApp',
        message: `Envoyer le résumé de l'inspection du véhicule ${insp.vehicle?.license_plate ?? ''} à ${insp.customer?.name ?? 'ce client'} ?`,
        confirmLabel: 'Envoyer sur WhatsApp',
        variant: 'success',
    });
    if (!ok) return;
    router.post(`/inspections/${insp.id}/whatsapp`, {}, { preserveScroll: true });
}

function countState(checklist, state) {
    return (checklist || []).filter((i) => i.state === state).length;
}
</script>

<template>
    <Head title="Inspections Visuelles (DVI)" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Inspection Visuelle Numérique (DVI)</h1>
                <p class="text-muted small mb-0">Contrôle multi-points (Vert / Orange / Rouge), photos avant-après et envoi WhatsApp au client</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNewInspection">
                <i class="bi bi-camera me-1"></i>Nouvelle inspection
            </button>
        </div>

        <div class="row g-3">
            <div v-if="!inspections.length" class="col-12">
                <div class="card border-0 shadow-sm p-4 text-center text-muted small">
                    Aucune inspection visuelle enregistrée pour le moment. Cliquez sur « Nouvelle inspection » pour démarrer.
                </div>
            </div>

            <div v-for="insp in inspections" :key="insp.id" class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                        <div>
                            <span class="fw-bold">{{ insp.vehicle?.license_plate }}</span>
                            <span class="text-muted small ms-1">{{ insp.vehicle?.brand }} {{ insp.vehicle?.model }}</span>
                            <div class="small text-muted">{{ insp.customer?.name ?? 'Client' }} • {{ insp.created_at }}</div>
                        </div>
                        <span v-if="insp.repair_order" class="badge bg-light text-dark border">{{ insp.repair_order.order_number }}</span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex gap-2 mb-3 small">
                            <span class="badge bg-success-subtle text-success border border-success-subtle">OK : {{ countState(insp.checklist, 'ok') }}</span>
                            <span class="badge bg-warning-subtle text-dark border border-warning-subtle">À surveiller : {{ countState(insp.checklist, 'attention') }}</span>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Urgent : {{ countState(insp.checklist, 'urgent') }}</span>
                        </div>

                        <ul class="list-group list-group-flush small mb-3">
                            <li v-for="(item, idx) in insp.checklist" :key="idx" class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                                <span>
                                    <strong>{{ item.category }} :</strong> {{ item.item }}
                                    <span v-if="item.note" class="text-muted fst-italic"> — {{ item.note }}</span>
                                </span>
                                <span
                                    class="badge"
                                    :class="item.state === 'ok' ? 'bg-success' : (item.state === 'attention' ? 'bg-warning text-dark' : 'bg-danger')"
                                >
                                    {{ item.state === 'ok' ? 'OK' : (item.state === 'attention' ? 'Surveiller' : 'Urgent') }}
                                </span>
                            </li>
                        </ul>

                        <p v-if="insp.summary_notes" class="small text-muted mb-2">{{ insp.summary_notes }}</p>

                        <div v-if="insp.photo_urls?.length" class="d-flex flex-wrap gap-2 mb-3">
                            <button
                                v-for="(url, i) in insp.photo_urls"
                                :key="i"
                                type="button"
                                class="p-0 border-0 bg-transparent"
                                style="cursor: zoom-in;"
                                title="Cliquer pour agrandir"
                                @click="openLightbox(insp.photo_urls, i, `Inspection — ${insp.vehicle?.license_plate ?? ''}`)"
                            >
                                <img :src="url" alt="Photo inspection" class="rounded border" style="width: 64px; height: 64px; object-fit: cover;" />
                            </button>
                        </div>
                    </div>
                    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
                        <small class="text-muted">
                            <span v-if="insp.whatsapp_sent_at"><i class="bi bi-check2-all text-success me-1"></i>Envoyé le {{ insp.whatsapp_sent_at }}</span>
                            <span v-else>Non encore envoyé sur WhatsApp</span>
                        </small>
                        <button type="button" class="btn btn-outline-success btn-sm" @click="sendWhatsapp(insp)">
                            <i class="bi bi-whatsapp me-1"></i>Envoyer au client
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <StaticModal id="modalNewInspection" title="Nouvelle inspection visuelle (DVI)" size="modal-lg">
            <form @submit.prevent="submitInspection">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Véhicule <span class="text-danger">*</span></label>
                        <select v-model="form.vehicle_id" class="form-select" required>
                            <option value="">-- Choisir un véhicule --</option>
                            <option v-for="v in vehicles" :key="v.id" :value="v.id">
                                {{ v.license_plate }} — {{ v.brand }} {{ v.model }} ({{ v.customer?.name ?? '' }})
                            </option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Ordre de réparation lié (facultatif)</label>
                        <select v-model="form.repair_order_id" class="form-select">
                            <option value="">-- Aucun --</option>
                            <option v-for="ro in repairOrders" :key="ro.id" :value="ro.id">
                                {{ ro.order_number }} ({{ ro.vehicle?.license_plate ?? '' }})
                            </option>
                        </select>
                    </div>
                </div>

                <div class="border rounded p-2 mb-3 bg-light">
                    <div class="small fw-bold mb-2">Points de contrôle rapides</div>
                    <div v-for="(row, idx) in form.checklist" :key="idx" class="row g-2 align-items-center mb-2 pb-2 border-bottom">
                        <div class="col-md-5 small">
                            <div class="fw-semibold">{{ row.item }}</div>
                            <div class="text-muted" style="font-size: 0.72rem;">{{ row.category }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="btn-group btn-group-sm w-100" role="group">
                                <button type="button" class="btn" :class="row.state === 'ok' ? 'btn-success' : 'btn-outline-success'" @click="row.state = 'ok'">OK</button>
                                <button type="button" class="btn" :class="row.state === 'attention' ? 'btn-warning text-dark' : 'btn-outline-warning'" @click="row.state = 'attention'">Surveiller</button>
                                <button type="button" class="btn" :class="row.state === 'urgent' ? 'btn-danger' : 'btn-outline-danger'" @click="row.state = 'urgent'">Urgent</button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <input v-model="row.note" type="text" class="form-control form-control-sm" placeholder="Note..." />
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Photos (max 6)</label>
                    <FileDropzone v-model="form.photos" :multiple="true" label="Glissez-déposez jusqu'à 6 photos ici ou cliquez" />
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Conclusion générale</label>
                    <textarea v-model="form.summary_notes" rows="3" class="form-control form-control-sm" placeholder="Résumé détaillé des constats pour le client..."></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Enregistrer l'inspection</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
