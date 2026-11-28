<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: TenantLayout });

const props = defineProps({
    photos: { type: Array, default: () => [] },
    services: { type: Array, default: () => [] },
    publicGarageUrl: { type: String, required: true },
    tenantSlug: { type: String, required: true },
    categories: { type: Array, default: () => [] },
});

// Upload photos établissement
const photoForm = useForm({
    photos: [],
});

function submitPhotos() {
    if (!photoForm.photos || photoForm.photos.length === 0) return;
    photoForm.post('/vitrine-prestations/photos', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            photoForm.reset('photos');
        },
    });
}

function deletePhoto(path) {
    if (!confirm('Supprimer cette photo de votre vitrine publique ?')) return;
    router.delete('/vitrine-prestations/photos', {
        data: { path },
        preserveScroll: true,
    });
}

// CRUD Prestations
const editingService = ref(null);
const serviceForm = useForm({
    name: '',
    category: 'entretien',
    description: '',
    price: 0,
    duration_minutes: 60,
    is_active: true,
    image: null,
    remove_image: false,
});

function openCreateService() {
    editingService.value = null;
    serviceForm.reset();
    serviceForm.category = 'entretien';
    serviceForm.price = 0;
    serviceForm.duration_minutes = 60;
    serviceForm.is_active = true;
    serviceForm.image = null;
    serviceForm.remove_image = false;
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalServiceForm'))?.show();
}

function openEditService(svc) {
    editingService.value = svc;
    serviceForm.name = svc.name;
    serviceForm.category = svc.category;
    serviceForm.description = svc.description || '';
    serviceForm.price = svc.price;
    serviceForm.duration_minutes = svc.duration_minutes;
    serviceForm.is_active = !!svc.is_active;
    serviceForm.image = null;
    serviceForm.remove_image = false;
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalServiceForm'))?.show();
}

function submitService() {
    const url = editingService.value
        ? `/vitrine-prestations/prestations/${editingService.value.id}`
        : '/vitrine-prestations/prestations';

    serviceForm.post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalServiceForm'))?.hide();
            serviceForm.reset();
            editingService.value = null;
        },
    });
}

function deleteService(svc) {
    if (!confirm(`Supprimer la prestation "${svc.name}" ?`)) return;
    router.delete(`/vitrine-prestations/prestations/${svc.id}`, {
        preserveScroll: true,
    });
}

function categoryLabel(value) {
    return props.categories.find((c) => c.value === value)?.label || value;
}

function formatMoney(n) {
    return new Intl.NumberFormat('fr-FR').format(Number(n || 0));
}
</script>

<template>
    <Head title="Ma Vitrine & Prestations" />

    <div class="container-fluid py-3">
        <!-- En-tête -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">
                    <i class="bi bi-shop-window text-primary me-2"></i>Ma Vitrine & Prestations
                </h1>
                <p class="text-muted small mb-0">
                    Publiez les photos de votre établissement et vos prestations pour attirer des clients sur la carte SOS Dépannage et recevoir des rendez-vous en ligne.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a :href="publicGarageUrl" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Voir ma page publique
                </a>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3" @click="openCreateService">
                    <i class="bi bi-plus-lg me-1"></i>Ajouter une prestation
                </button>
            </div>
        </div>

        <!-- Section 1 : Photos de l'établissement -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h6 fw-bold mb-0">
                        <i class="bi bi-images text-primary me-2"></i>Photos de mon établissement ({{ photos.length }}/12)
                    </h2>
                    <div class="text-muted small">Ces images s'affichent sous forme de carrousel sur les cartes de la page <code>/sos-depannage</code> et sur votre page publique.</div>
                </div>
            </div>
            <div class="card-body">
                <form @submit.prevent="submitPhotos" class="mb-4">
                    <FileDropzone
                        v-model="photoForm.photos"
                        :multiple="true"
                        accept="image/jpeg,image/png,image/webp"
                        label="Glissez-déposez une ou plusieurs photos de votre atelier / façade (JPG, PNG, WEBP — max 6 Mo)"
                    />
                    <div v-if="photoForm.errors.photos" class="text-danger small mt-1">{{ photoForm.errors.photos }}</div>
                    <div class="mt-3 d-flex justify-content-end" v-if="photoForm.photos && photoForm.photos.length">
                        <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4" :disabled="photoForm.processing">
                            <span v-if="photoForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                            <i v-else class="bi bi-cloud-upload me-1"></i>Publier {{ photoForm.photos.length }} photo(s)
                        </button>
                    </div>
                </form>

                <div v-if="photos.length === 0" class="text-center py-4 bg-light rounded-3 text-muted small">
                    <i class="bi bi-camera fs-2 d-block mb-2 opacity-50"></i>
                    Aucune photo publiée pour le moment. Ajoutez des photos de votre atelier pour inspirer confiance aux conducteurs !
                </div>

                <div v-else class="row g-3">
                    <div v-for="photo in photos" :key="photo.path" class="col-6 col-md-4 col-lg-3 col-xl-2">
                        <div class="position-relative rounded-3 overflow-hidden border shadow-sm bg-light" style="aspect-ratio: 4/3;">
                            <img :src="photo.url" alt="Photo établissement" class="w-100 h-100" style="object-fit: cover;" />
                            <span v-if="photo.index === 0" class="badge bg-primary position-absolute top-0 start-0 m-2">Couverture</span>
                            <button
                                type="button"
                                class="btn btn-dark btn-sm position-absolute top-0 end-0 m-2 rounded-circle p-0 d-flex align-items-center justify-content-center"
                                style="width: 28px; height: 28px; opacity: 0.9;"
                                title="Supprimer cette photo"
                                @click="deletePhoto(photo.path)"
                            >
                                <i class="bi bi-trash small"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2 : Prestations & Services -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h6 fw-bold mb-0">
                        <i class="bi bi-tools text-primary me-2"></i>Mes Prestations ({{ services.length }})
                    </h2>
                    <div class="text-muted small">Les clients choisissent une ou plusieurs prestations sur votre page en ligne pour prendre rendez-vous directement.</div>
                </div>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3" @click="openCreateService">
                    <i class="bi bi-plus-lg me-1"></i>Nouvelle prestation
                </button>
            </div>
            <div class="card-body">
                <div v-if="services.length === 0" class="text-center py-5 bg-light rounded-3 text-muted">
                    <i class="bi bi-wrench-adjustable-circle fs-1 d-block mb-2 opacity-50"></i>
                    <div class="fw-semibold mb-1">Aucune prestation configurée</div>
                    <p class="small mb-3">Ajoutez vos services (Vidange, Diagnostic OBD, Freinage, Visite technique, Remorquage...) avec leur tarif indicatif.</p>
                    <button type="button" class="btn btn-primary btn-sm rounded-pill px-4" @click="openCreateService">
                        <i class="bi bi-plus-lg me-1"></i>Créer ma première prestation
                    </button>
                </div>

                <div v-else class="row g-3">
                    <div v-for="svc in services" :key="svc.id" class="col-md-6 col-xl-4">
                        <div class="card h-100 border rounded-4 overflow-hidden shadow-sm">
                            <div v-if="svc.image_url" style="height: 150px; overflow: hidden;">
                                <img :src="svc.image_url" :alt="svc.name" class="w-100 h-100" style="object-fit: cover;" />
                            </div>
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <span class="badge bg-primary-subtle text-primary rounded-pill">{{ categoryLabel(svc.category) }}</span>
                                    <span :class="svc.is_active ? 'badge bg-success-subtle text-success' : 'badge bg-secondary-subtle text-secondary'">
                                        {{ svc.is_active ? 'Visible' : 'Masquée' }}
                                    </span>
                                </div>
                                <h3 class="h6 fw-bold mb-1 mt-1">{{ svc.name }}</h3>
                                <p v-if="svc.description" class="text-muted small mb-3 flex-grow-1">{{ svc.description }}</p>
                                <div v-else class="flex-grow-1"></div>

                                <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2">
                                    <div>
                                        <div class="fw-bold text-primary">
                                            {{ svc.price > 0 ? `${formatMoney(svc.price)} FCFA` : 'Sur devis' }}
                                        </div>
                                        <div class="text-muted" style="font-size: 0.75rem;">
                                            <i class="bi bi-clock me-1"></i>~{{ svc.duration_minutes }} min
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" @click="openEditService(svc)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" @click="deleteService(svc)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Création / Modification Prestation -->
    <div class="modal fade" id="modalServiceForm" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-tools text-primary me-2"></i>
                        {{ editingService ? 'Modifier la prestation' : 'Nouvelle prestation' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form @submit.prevent="submitService">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label small fw-semibold">Nom de la prestation *</label>
                                <input v-model="serviceForm.name" type="text" class="form-control" required placeholder="Ex: Vidange complète + filtre à huile" />
                                <div v-if="serviceForm.errors.name" class="text-danger small mt-1">{{ serviceForm.errors.name }}</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Catégorie *</label>
                                <select v-model="serviceForm.category" class="form-select" required>
                                    <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Tarif indicatif (FCFA) *</label>
                                <input v-model.number="serviceForm.price" type="number" min="0" step="500" class="form-control" required />
                                <div class="form-text">Mettez 0 pour afficher « Sur devis ».</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Durée estimée (minutes) *</label>
                                <input v-model.number="serviceForm.duration_minutes" type="number" min="10" max="1440" class="form-control" required />
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Description (facultatif)</label>
                                <textarea v-model="serviceForm.description" rows="2" class="form-control" placeholder="Détails inclus dans cette prestation..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Image de la prestation (facultatif)</label>
                                <div v-if="editingService?.image_url && !serviceForm.remove_image" class="d-flex align-items-center gap-3 mb-2 p-2 border rounded-3 bg-light">
                                    <img :src="editingService.image_url" alt="" class="rounded" style="width: 64px; height: 48px; object-fit: cover;" />
                                    <div class="small text-muted flex-grow-1">Image actuelle</div>
                                    <button type="button" class="btn btn-outline-danger btn-sm" @click="serviceForm.remove_image = true">
                                        Retirer
                                    </button>
                                </div>
                                <FileDropzone
                                    v-model="serviceForm.image"
                                    :multiple="false"
                                    accept="image/jpeg,image/png,image/webp"
                                    label="Glissez-déposez une image pour illustrer cette prestation (facultatif)"
                                />
                                <div v-if="serviceForm.errors.image" class="text-danger small mt-1">{{ serviceForm.errors.image }}</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input id="svcActiveSwitch" v-model="serviceForm.is_active" class="form-check-input" type="checkbox" />
                                    <label class="form-check-label small fw-semibold" for="svcActiveSwitch">
                                        Prestation visible sur ma page publique
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4" :disabled="serviceForm.processing">
                            <span v-if="serviceForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                            {{ editingService ? 'Enregistrer' : 'Ajouter la prestation' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
