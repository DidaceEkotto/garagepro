<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import LandingFooter from '../Components/LandingFooter.vue';
import BackToTop from '../Components/BackToTop.vue';
import { useI18n } from '../composables/useI18n';

const props = defineProps({
    appName: { type: String, default: 'MboaCar' },
    structureEmail: { type: String, default: 'jeannotgates@gmail.com' },
});

const { t } = useI18n();
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({
    name: '',
    email: '',
    phone: '',
    subject: '',
    message: '',
});

function submit() {
    form.post('/contact', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head :title="t('contact.title')" />

    <!-- Header avec Navbar -->
    <header
        class="text-white position-relative"
        style="background: linear-gradient(125deg, rgba(15, 23, 42, 0.94) 0%, rgba(30, 58, 138, 0.85) 60%, rgba(37, 99, 235, 0.72) 100%), url('/images/hero-garage.jpg') center/cover no-repeat;"
    >
        <nav class="navbar navbar-expand-lg navbar-dark">
            <div class="container py-2">
                <Link class="navbar-brand fw-bold d-flex align-items-center gap-2" href="/">
                    <img
                        :src="page.props.app?.site?.logo_url || '/assets/images/logo.png'"
                        :alt="appName"
                        class="bg-white rounded-3 px-2 py-1 shadow-sm"
                        style="height: 38px; width: auto; object-fit: contain;"
                    />
                </Link>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#contactNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div id="contactNav" class="collapse navbar-collapse mobile-nav-collapse">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-lg-2">
                        <li class="nav-item"><Link class="nav-link text-white" href="/">{{ t('auth.backHome') }}</Link></li>
                        <li class="nav-item"><Link class="nav-link text-white active fw-semibold" href="/contact">{{ t('nav.contact') }}</Link></li>
                    </ul>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <LanguageSwitcher variant="light" />
                        <template v-if="page.props.auth?.dashboardUrl && !page.props.auth?.isDemo">
                            <a :href="page.props.auth.dashboardUrl" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold d-inline-flex align-items-center gap-1">
                                <i class="bi bi-speedometer2"></i><span>Dashboard</span>
                            </a>
                        </template>
                        <template v-else-if="page.props.auth?.isDemo">
                            <a href="/demo/quitter?to=login" class="btn btn-outline-light btn-sm rounded-pill px-3">{{ t('nav.login') }}</a>
                            <a href="/demo/quitter?to=register" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold">{{ t('nav.register') }}</a>
                        </template>
                        <template v-else>
                            <Link href="/connexion" class="btn btn-outline-light btn-sm rounded-pill px-3">{{ t('nav.login') }}</Link>
                            <Link href="/inscription" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold">{{ t('nav.register') }}</Link>
                        </template>
                    </div>
                </div>
            </div>
        </nav>

        <div class="container py-5 text-center hero-animate">
            <span class="badge bg-primary bg-opacity-75 border border-light border-opacity-25 rounded-pill px-3 py-2 mb-3">
                <i class="bi bi-headset me-1"></i>{{ t('contact.badge') }}
            </span>
            <h1 class="display-5 fw-bold mb-2">{{ t('contact.title') }}</h1>
            <p class="lead text-white-50 mx-auto mb-0" style="max-width: 640px;">
                {{ t('contact.subtitle') }}
            </p>
        </div>
    </header>

    <!-- Contenu Contact -->
    <main class="py-5 bg-light">
        <div class="container py-3">
            <div class="row g-4 align-items-stretch">
                <!-- Colonne gauche : Coordonnées -->
                <div class="col-lg-5 hero-animate">
                    <div class="card border-0 shadow-sm h-100 rounded-4 p-4 text-white" style="background: linear-gradient(155deg, #0f172a 0%, #1e3a8a 100%);">
                        <div class="card-body d-flex flex-column justify-content-between">
                            <div>
                                <h2 class="h4 fw-bold mb-2">{{ t('contact.infoTitle') }}</h2>
                                <p class="text-white-50 small mb-4">{{ t('contact.infoDesc') }}</p>

                                <div class="vstack gap-4">
                                    <div class="d-flex align-items-start gap-3">
                                        <span class="bg-primary bg-opacity-25 border border-primary border-opacity-50 rounded-3 p-3 text-info">
                                            <i class="bi bi-envelope-at fs-4"></i>
                                        </span>
                                        <div>
                                            <div class="small text-white-50">{{ t('contact.emailLabel') }}</div>
                                            <a :href="`mailto:${structureEmail}`" class="text-white fw-semibold text-decoration-none fs-6">
                                                {{ structureEmail }}
                                            </a>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-start gap-3">
                                        <span class="bg-primary bg-opacity-25 border border-primary border-opacity-50 rounded-3 p-3 text-info">
                                            <i class="bi bi-clock fs-4"></i>
                                        </span>
                                        <div>
                                            <div class="small text-white-50">{{ t('contact.hoursLabel') }}</div>
                                            <div class="fw-semibold">{{ t('contact.hoursValue') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="pt-4 mt-4 border-top border-light border-opacity-10 d-flex flex-wrap gap-2">
                                <a href="/demo" class="btn btn-warning btn-sm fw-semibold text-dark px-3">
                                    <i class="bi bi-play-circle-fill me-1"></i>{{ t('nav.demo') }}
                                </a>
                                <Link href="/inscription" class="btn btn-outline-light btn-sm px-3">
                                    {{ t('nav.register') }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Colonne droite : Formulaire -->
                <div class="col-lg-7 hero-animate-delay-1">
                    <div class="card border-0 shadow-sm h-100 rounded-4 p-4">
                        <div class="card-body">
                            <h2 class="h4 fw-bold mb-4">{{ t('contact.formTitle') }}</h2>

                            <div v-if="flashSuccess" class="alert alert-success d-flex align-items-center gap-2 mb-4">
                                <i class="bi bi-check-circle-fill fs-5"></i>
                                <span>{{ flashSuccess }}</span>
                            </div>

                            <form @submit.prevent="submit">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="contact_name" class="form-label small fw-semibold">{{ t('contact.name') }}</label>
                                        <input
                                            id="contact_name"
                                            v-model="form.name"
                                            type="text"
                                            class="form-control"
                                            :class="{ 'is-invalid': form.errors.name }"
                                            required
                                        />
                                        <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="contact_email" class="form-label small fw-semibold">{{ t('contact.email') }}</label>
                                        <input
                                            id="contact_email"
                                            v-model="form.email"
                                            type="email"
                                            class="form-control"
                                            :class="{ 'is-invalid': form.errors.email }"
                                            required
                                        />
                                        <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="contact_phone" class="form-label small fw-semibold">{{ t('contact.phone') }}</label>
                                        <input
                                            id="contact_phone"
                                            v-model="form.phone"
                                            type="text"
                                            class="form-control"
                                            :class="{ 'is-invalid': form.errors.phone }"
                                        />
                                        <div v-if="form.errors.phone" class="invalid-feedback">{{ form.errors.phone }}</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="contact_subject" class="form-label small fw-semibold">{{ t('contact.subject') }}</label>
                                        <input
                                            id="contact_subject"
                                            v-model="form.subject"
                                            type="text"
                                            class="form-control"
                                            :class="{ 'is-invalid': form.errors.subject }"
                                            required
                                        />
                                        <div v-if="form.errors.subject" class="invalid-feedback">{{ form.errors.subject }}</div>
                                    </div>

                                    <div class="col-12">
                                        <label for="contact_message" class="form-label small fw-semibold">{{ t('contact.message') }}</label>
                                        <textarea
                                            id="contact_message"
                                            v-model="form.message"
                                            rows="5"
                                            class="form-control"
                                            :class="{ 'is-invalid': form.errors.message }"
                                            required
                                        ></textarea>
                                        <div v-if="form.errors.message" class="invalid-feedback">{{ form.errors.message }}</div>
                                    </div>

                                    <div class="col-12 pt-2">
                                        <button type="submit" class="btn btn-primary btn-lg px-4 fw-semibold" :disabled="form.processing">
                                            <i class="bi bi-send me-2"></i>{{ form.processing ? t('contact.sending') : t('contact.send') }}
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <LandingFooter :app-name="appName" />

    <BackToTop />
</template>
