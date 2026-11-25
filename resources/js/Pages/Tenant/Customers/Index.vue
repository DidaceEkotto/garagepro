<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useLightbox } from '@/composables/useLightbox';
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const { openLightbox } = useLightbox();

const props = defineProps({
    customers: Object,
    search: String,
    defaultDialCode: { type: String, default: '+237' },
    countries: { type: Array, default: () => [] },
});

const query = ref(props.search ?? '');
const remindingCustomer = ref(null);
const remindingWhatsappCustomer = ref(null);

const form = useForm({
    name: '',
    first_name: '',
    gender: 'homme',
    id_number: '',
    phone_dial_code: props.defaultDialCode || '+237',
    phone: '',
    email: '',
    type: 'particulier',
    city: '',
    address: '',
    company_name: '',
    profession: '',
    date_of_birth: '',
    emergency_contact: '',
    description: '',
    photo: null,
    credit_limit: 0,
});

const remindForm = useForm({
    subject: 'Suivi de votre véhicule',
    message: 'Bonjour, nous vous contactons concernant votre véhicule ou un rendez-vous au sein de notre atelier. N\'hésitez pas à nous répondre ou à passer nous voir.',
});

function openRemind(customer) {
    remindingCustomer.value = customer;
    remindForm.subject = 'Suivi / Relance client';
    remindForm.message = `Bonjour ${customer.name},\n\nNous vous contactons pour le suivi de votre dossier au garage. N'hésitez pas à nous contacter pour toute information.\n\nCordialement.`;
}

function submitRemind() {
    if (!remindingCustomer.value) return;
    remindForm.post(`/clients/${remindingCustomer.value.id}/relancer`, {
        onSuccess: () => {
            remindingCustomer.value = null;
            window.bootstrap?.Modal.getInstance(document.getElementById('modalRemindCustomer'))?.hide();
        },
    });
}

const whatsappForm = useForm({
    message: '',
});

function openWhatsappRemind(customer) {
    remindingWhatsappCustomer.value = customer;
    whatsappForm.message = `Bonjour ${customer.name}, nous vous contactons depuis votre garage concernant le suivi de votre véhicule. N'hésitez pas à nous répondre sur WhatsApp !`;
}

function submitWhatsappRemind() {
    if (!remindingWhatsappCustomer.value) return;
    whatsappForm.post(`/clients/${remindingWhatsappCustomer.value.id}/relancer-whatsapp`, {
        onSuccess: () => {
            remindingWhatsappCustomer.value = null;
            window.bootstrap?.Modal.getInstance(document.getElementById('modalRemindWhatsapp'))?.hide();
        },
    });
}

const globalLookupStatus = ref('');

async function lookupGlobalCustomer() {
    globalLookupStatus.value = '';
    const params = new URLSearchParams();
    if (form.phone) params.set('phone', form.phone);
    if (form.id_number) params.set('id_number', form.id_number);
    if (!form.phone && !form.id_number) {
        globalLookupStatus.value = 'Saisissez un numéro WhatsApp ou une CNI puis cliquez pour pré-remplir.';
        return;
    }

    try {
        const res = await fetch(`/clients/lookup-global?${params.toString()}`, { headers: { Accept: 'application/json' } });
        const json = await res.json();
        if (!json.found) {
            globalLookupStatus.value = 'Aucun compte client MboaCar existant trouvé.';
            return;
        }
        const d = json.data;
        if (d.name) form.name = d.name;
        if (d.first_name) form.first_name = d.first_name;
        if (d.gender) form.gender = d.gender;
        if (d.id_number) form.id_number = d.id_number;
        if (d.phone_dial_code) form.phone_dial_code = d.phone_dial_code;
        if (d.phone) form.phone = d.phone;
        if (d.email) form.email = d.email;
        if (d.profession) form.profession = d.profession;
        if (d.date_of_birth) form.date_of_birth = d.date_of_birth;
        if (d.emergency_contact) form.emergency_contact = d.emergency_contact;
        globalLookupStatus.value = 'Identité MboaCar trouvée et pré-remplie automatiquement !';
    } catch {
        globalLookupStatus.value = 'Impossible de vérifier pour le moment.';
    }
}

function doSearch() {
    router.get('/clients', { q: query.value }, { preserveState: true });
}

function createCustomer() {
    form.post('/clients', {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            form.phone_dial_code = props.defaultDialCode || '+237';
            form.gender = 'homme';
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddCustomer'))?.hide();
        },
    });
}

const editingCustomer = ref(null);
const editForm = useForm({
    _method: 'PUT',
    name: '',
    first_name: '',
    gender: 'homme',
    id_number: '',
    phone_dial_code: props.defaultDialCode || '+237',
    phone: '',
    email: '',
    type: 'particulier',
    city: '',
    address: '',
    company_name: '',
    profession: '',
    date_of_birth: '',
    emergency_contact: '',
    description: '',
    photo: null,
    credit_limit: 0,
});

async function openEditCustomer(c) {
    editingCustomer.value = c;
    editForm.clearErrors();
    editForm.name = c.name ?? '';
    editForm.first_name = c.first_name ?? '';
    editForm.gender = c.gender ?? 'homme';
    editForm.id_number = c.id_number ?? '';
    editForm.phone_dial_code = c.phone_dial_code || props.defaultDialCode || '+237';
    editForm.phone = c.phone ?? '';
    editForm.email = c.email ?? '';
    editForm.type = c.type ?? 'particulier';
    editForm.city = c.city ?? '';
    editForm.address = c.address ?? '';
    editForm.company_name = c.company_name ?? '';
    editForm.profession = c.profession ?? '';
    editForm.date_of_birth = c.date_of_birth ? String(c.date_of_birth).slice(0, 10) : '';
    editForm.emergency_contact = c.emergency_contact ?? '';
    editForm.description = c.description ?? '';
    editForm.credit_limit = c.credit_limit ?? 0;
    editForm.photo = null;
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalEditCustomer'))?.show();
}

function updateCustomer() {
    if (!editingCustomer.value) return;
    editForm.post(`/clients/${editingCustomer.value.id}`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            editingCustomer.value = null;
            editForm.reset('photo');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalEditCustomer'))?.hide();
        },
    });
}
</script>

<template>
    <Head title="Clients" />

    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Clients</h1>
                <p class="text-muted small mb-0">Recherche rapide par nom, téléphone ou plaque d'immatriculation</p>
            </div>
            <div class="d-flex gap-2">
                <a href="/export-excel/clients" class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
                </a>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddCustomer">
                    <i class="bi bi-person-plus me-1"></i>Nouveau client
                </button>
            </div>
        </div>

        <form class="input-group mb-3 shadow-sm" @submit.prevent="doSearch">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
            <input v-model="query" type="search" class="form-control border-start-0" placeholder="Rechercher par nom, téléphone ou immatriculation..." />
            <button class="btn btn-primary px-4" type="submit">Rechercher</button>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nom</th>
                            <th>Téléphone</th>
                            <th>Type</th>
                            <th>Véhicules</th>
                            <th class="text-end">Fiche</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in customers.data" :key="c.id">
                            <td class="fw-semibold">
                                <div class="d-flex align-items-center gap-2">
                                    <img
                                        v-if="c.photo_path"
                                        :src="`/storage/${c.photo_path}`"
                                        :alt="c.name"
                                        class="rounded-circle border"
                                        style="width: 34px; height: 34px; object-fit: cover; cursor: zoom-in;"
                                        title="Cliquer pour agrandir"
                                        @click="openLightbox([`/storage/${c.photo_path}`], 0, c.name)"
                                    />
                                    <div>
                                        <div>{{ c.name }} <span v-if="c.first_name" class="fw-normal">{{ c.first_name }}</span></div>
                                        <div v-if="c.id_number" class="text-muted fw-normal" style="font-size: 0.75rem;">CNI : {{ c.id_number }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ c.phone }}</td>
                            <td><span class="badge bg-secondary">{{ c.type }}</span></td>
                            <td>{{ c.vehicles?.length ?? 0 }}</td>
                            <td class="text-end text-nowrap">
                                <button
                                    type="button"
                                    class="btn btn-outline-primary btn-sm me-1"
                                    @click="openEditCustomer(c)"
                                >
                                    <i class="bi bi-pencil-square me-1"></i>Modifier
                                </button>
                                <button
                                    v-if="c.whatsapp_phone || c.phone"
                                    type="button"
                                    class="btn btn-outline-success btn-sm me-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalRemindWhatsapp"
                                    @click="openWhatsappRemind(c)"
                                >
                                    <i class="bi bi-whatsapp me-1"></i>WhatsApp
                                </button>
                                <button
                                    v-if="c.email"
                                    type="button"
                                    class="btn btn-outline-info btn-sm me-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalRemindCustomer"
                                    @click="openRemind(c)"
                                >
                                    <i class="bi bi-envelope-paper me-1"></i>Relancer
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalAddCustomer" title="Nouveau client" size="modal-lg">
            <form @submit.prevent="createCustomer">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-2 mb-3 rounded bg-light border small">
                    <span class="text-muted">
                        <i class="bi bi-person-badge text-primary me-1"></i>
                        {{ globalLookupStatus || 'Le client a déjà visité un autre garage MboaCar ? Saisissez son WhatsApp ou CNI puis pré-remplissez.' }}
                    </span>
                    <button type="button" class="btn btn-outline-primary btn-sm" @click="lookupGlobalCustomer">
                        <i class="bi bi-search me-1"></i>Pré-remplir compte MboaCar
                    </button>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-5">
                        <label class="form-label small">Nom <span class="text-danger">*</span></label>
                        <input v-model="form.name" type="text" class="form-control" :class="{ 'is-invalid': form.errors.name }" required />
                        <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Prénom (facultatif)</label>
                        <input v-model="form.first_name" type="text" class="form-control" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Sexe <span class="text-danger">*</span></label>
                        <select v-model="form.gender" class="form-select" required>
                            <option value="homme">Homme</option>
                            <option value="femme">Femme</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">N° CNI ou Passeport <span class="text-danger">*</span></label>
                        <input v-model="form.id_number" type="text" class="form-control" :class="{ 'is-invalid': form.errors.id_number }" placeholder="Ex: 112233445 ou CM123456" required />
                        <div v-if="form.errors.id_number" class="invalid-feedback">{{ form.errors.id_number }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Téléphone WhatsApp <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select v-model="form.phone_dial_code" class="form-select" style="max-width: 110px;">
                                <option :value="defaultDialCode">{{ defaultDialCode }}</option>
                                <option v-for="c in countries" :key="c.code" :value="c.dial_code">{{ c.code }} ({{ c.dial_code }})</option>
                            </select>
                            <input v-model="form.phone" type="tel" class="form-control" :class="{ 'is-invalid': form.errors.phone }" placeholder="6XX XXX XXX" required />
                            <div v-if="form.errors.phone" class="invalid-feedback">{{ form.errors.phone }}</div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Type</label>
                        <select v-model="form.type" class="form-select">
                            <option value="particulier">Particulier</option>
                            <option value="entreprise">Entreprise</option>
                            <option value="flotte">Flotte</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Ville (facultatif)</label>
                        <input v-model="form.city" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">E-mail (facultatif)</label>
                        <input v-model="form.email" type="email" class="form-control" />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Profession (facultatif)</label>
                        <input v-model="form.profession" type="text" class="form-control" placeholder="Ex: Commerçant" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Date de naissance (facultatif)</label>
                        <DatePickerInput v-model="form.date_of_birth" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Contact d'urgence (facultatif)</label>
                        <input v-model="form.emergency_contact" type="text" class="form-control" placeholder="Nom & téléphone" />
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Adresse / Quartier (facultatif)</label>
                    <input v-model="form.address" type="text" class="form-control" placeholder="Ex: Bonapriso, Rue..." />
                </div>

                <div class="mb-3">
                    <label class="form-label small">Description (facultatif)</label>
                    <textarea v-model="form.description" rows="2" class="form-control" placeholder="Notes ou informations complémentaires pour identifier le client..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label small">Photo de profil (facultatif)</label>
                    <FileDropzone v-model="form.photo" label="Glissez-déposez la photo de profil ou cliquez pour choisir" />
                    <div v-if="form.errors.photo" class="text-danger small mt-1">{{ form.errors.photo }}</div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalEditCustomer" title="Modifier les informations du client" size="modal-lg">
            <form v-if="editingCustomer" @submit.prevent="updateCustomer">
                <div class="row g-2 mb-3">
                    <div class="col-md-5">
                        <label class="form-label small">Nom <span class="text-danger">*</span></label>
                        <input v-model="editForm.name" type="text" class="form-control" :class="{ 'is-invalid': editForm.errors.name }" required />
                        <div v-if="editForm.errors.name" class="invalid-feedback">{{ editForm.errors.name }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Prénom (facultatif)</label>
                        <input v-model="editForm.first_name" type="text" class="form-control" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Sexe</label>
                        <select v-model="editForm.gender" class="form-select">
                            <option value="homme">Homme</option>
                            <option value="femme">Femme</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">N° CNI ou Passeport</label>
                        <input v-model="editForm.id_number" type="text" class="form-control" :class="{ 'is-invalid': editForm.errors.id_number }" />
                        <div v-if="editForm.errors.id_number" class="invalid-feedback">{{ editForm.errors.id_number }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Téléphone WhatsApp <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select v-model="editForm.phone_dial_code" class="form-select" style="max-width: 110px;">
                                <option :value="defaultDialCode">{{ defaultDialCode }}</option>
                                <option v-for="c in countries" :key="c.code" :value="c.dial_code">{{ c.code }} ({{ c.dial_code }})</option>
                            </select>
                            <input v-model="editForm.phone" type="tel" class="form-control" :class="{ 'is-invalid': editForm.errors.phone }" required />
                            <div v-if="editForm.errors.phone" class="invalid-feedback">{{ editForm.errors.phone }}</div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Type</label>
                        <select v-model="editForm.type" class="form-select">
                            <option value="particulier">Particulier</option>
                            <option value="entreprise">Entreprise</option>
                            <option value="flotte">Flotte</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Ville (facultatif)</label>
                        <input v-model="editForm.city" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">E-mail (facultatif)</label>
                        <input v-model="editForm.email" type="email" class="form-control" :class="{ 'is-invalid': editForm.errors.email }" />
                        <div v-if="editForm.errors.email" class="invalid-feedback">{{ editForm.errors.email }}</div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Profession (facultatif)</label>
                        <input v-model="editForm.profession" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Date de naissance (facultatif)</label>
                        <DatePickerInput v-model="editForm.date_of_birth" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Contact d'urgence (facultatif)</label>
                        <input v-model="editForm.emergency_contact" type="text" class="form-control" />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Adresse / Quartier (facultatif)</label>
                        <input v-model="editForm.address" type="text" class="form-control" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Entreprise (si pro / flotte)</label>
                        <input v-model="editForm.company_name" type="text" class="form-control" />
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Description (facultatif)</label>
                    <textarea v-model="editForm.description" rows="2" class="form-control"></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label small">Nouvelle photo de profil (facultatif)</label>
                    <div v-if="editingCustomer.photo_path" class="mb-2 d-flex align-items-center gap-2">
                        <img
                            :src="`/storage/${editingCustomer.photo_path}`"
                            :alt="editingCustomer.name"
                            class="rounded border"
                            style="width: 48px; height: 48px; object-fit: cover; cursor: zoom-in;"
                            @click="openLightbox([`/storage/${editingCustomer.photo_path}`], 0, editingCustomer.name)"
                        />
                        <span class="small text-muted">Photo actuelle (cliquer pour agrandir)</span>
                    </div>
                    <FileDropzone v-model="editForm.photo" label="Glissez-déposez une nouvelle photo ou cliquez pour remplacer" />
                    <div v-if="editForm.errors.photo" class="text-danger small mt-1">{{ editForm.errors.photo }}</div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="editForm.processing">Enregistrer les modifications</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalRemindCustomer" title="Relancer le client par e-mail">
            <form @submit.prevent="submitRemind">
                <p class="small text-muted mb-3">
                    Destinataire : <strong>{{ remindingCustomer?.name }}</strong> ({{ remindingCustomer?.email }})
                </p>
                <div class="mb-3">
                    <label class="form-label small">Objet</label>
                    <input v-model="remindForm.subject" type="text" class="form-control" required />
                </div>
                <div class="mb-4">
                    <label class="form-label small">Message</label>
                    <textarea v-model="remindForm.message" rows="4" class="form-control" required></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="remindForm.processing">
                        <i class="bi bi-send me-1"></i>Envoyer la relance
                    </button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalRemindWhatsapp" title="Relancer le client via WhatsApp">
            <form @submit.prevent="submitWhatsappRemind">
                <p class="small text-muted mb-3">
                    Destinataire : <strong>{{ remindingWhatsappCustomer?.name }}</strong>
                    ({{ remindingWhatsappCustomer?.whatsapp_phone || remindingWhatsappCustomer?.phone }})
                </p>
                <div class="mb-4">
                    <label class="form-label small">Message WhatsApp</label>
                    <textarea v-model="whatsappForm.message" rows="4" class="form-control" required></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success px-4" :disabled="whatsappForm.processing">
                        <i class="bi bi-whatsapp me-1"></i>Envoyer via WhatsApp
                    </button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
