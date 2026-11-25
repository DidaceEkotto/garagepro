<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LanguageSwitcher from '../../Components/LanguageSwitcher.vue';

defineProps({
    tenantName: { type: String, default: null },
});

const page = usePage();
const siteLogoUrl = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');

const form = useForm({
    email: '',
    channel: 'email',
    website: '', // Honeypot anti-bot
});

function submit() {
    form.email = (form.email || '').trim();
    form.post('/mot-de-passe-oublie');
}
</script>

<template>
    <Head title="Mot de passe oublié" />
    <div class="min-vh-100 bg-light d-flex flex-column justify-content-center py-4 px-3">
        <div class="container" style="max-width: 500px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <Link href="/connexion" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i>Retour connexion
                </Link>
                <LanguageSwitcher />
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img
                            :src="siteLogoUrl"
                            alt="MboaCar"
                            class="mb-3 rounded-3 p-1 bg-white border shadow-sm"
                            style="height: 44px; width: auto; object-fit: contain;"
                        />
                        <h1 class="h4 fw-bold mb-1">Mot de passe oublié</h1>
                        <p class="text-muted small mb-0">
                            Réservé au <strong>propriétaire principal du compte</strong>
                            <span v-if="tenantName"> ({{ tenantName }})</span>
                        </p>
                    </div>

                    <div class="alert alert-light border small mb-4">
                        <i class="bi bi-shield-lock-fill text-primary me-1"></i>
                        Choisissez comment recevoir votre code de sécurité à 5 caractères (valable 5 minutes).
                    </div>

                    <form @submit.prevent="submit">
                        <!-- Champ honeypot caché anti-bot -->
                        <div style="position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden;" aria-hidden="true">
                            <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" />
                        </div>

                        <div class="mb-3">
                            <label for="resetEmail" class="form-label small fw-semibold">Adresse e-mail du propriétaire</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input
                                    id="resetEmail"
                                    v-model="form.email"
                                    type="email"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.email }"
                                    placeholder="proprietaire@garage.cm"
                                    autocomplete="email"
                                    required
                                    autofocus
                                />
                                <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-semibold d-block mb-2">Canal de réception du code OTP</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <button
                                        type="button"
                                        class="btn w-100 py-3 d-flex flex-column align-items-center gap-1 rounded-3"
                                        :class="form.channel === 'email' ? 'btn-primary shadow-sm' : 'btn-outline-secondary'"
                                        @click="form.channel = 'email'"
                                    >
                                        <i class="bi bi-envelope-check fs-4"></i>
                                        <span class="small fw-semibold">Par E-mail</span>
                                    </button>
                                </div>
                                <div class="col-6">
                                    <button
                                        type="button"
                                        class="btn w-100 py-3 d-flex flex-column align-items-center gap-1 rounded-3"
                                        :class="form.channel === 'whatsapp' ? 'btn-success shadow-sm' : 'btn-outline-secondary'"
                                        @click="form.channel = 'whatsapp'"
                                    >
                                        <i class="bi bi-whatsapp fs-4"></i>
                                        <span class="small fw-semibold">Par WhatsApp</span>
                                    </button>
                                </div>
                            </div>
                            <div v-if="form.errors.channel" class="text-danger small mt-2">{{ form.errors.channel }}</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-3" :disabled="form.processing">
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2"></span>
                            {{ form.processing ? 'Envoi du code...' : 'Recevoir mon code de sécurité' }}
                        </button>

                        <div class="text-center text-muted" style="font-size: 0.75rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            Employé / Mécanicien ? Demandez au gérant de votre espace de réinitialiser votre accès.
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
