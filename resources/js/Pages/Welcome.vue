<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import LandingAiShowcase from '../Components/LandingAiShowcase.vue';
import LandingFooter from '../Components/LandingFooter.vue';
import PwaInstallButton from '../Components/PwaInstallButton.vue';
import BackToTop from '../Components/BackToTop.vue';
import { useI18n } from '../composables/useI18n';

const props = defineProps({
    appName: { type: String, default: 'MboaCar' },
    trialDays: { type: Number, default: 7 },
    monthlyPrice: { type: Number, default: 10000 },
    baseDomain: { type: String, default: 'mboacar.com' },
    testimonials: { type: Array, default: () => [] },
    partners: { type: Array, default: () => [] },
});

const page = usePage();
const siteLogoUrl = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');
const isAuthenticatedPro = computed(() => Boolean(page.props.auth?.dashboardUrl && !page.props.auth?.isDemo));
const isDemoSession = computed(() => Boolean(page.props.auth?.isDemo));
const dashboardUrl = computed(() => page.props.auth?.dashboardUrl || '/tableau-de-bord');
const authTenantName = computed(() => page.props.auth?.tenant?.name || '');

const marqueePartners = computed(() => {
    const list = props.partners || [];
    if (!list.length) return [];
    const repeat = Math.max(2, Math.ceil(10 / list.length));
    const items = [];
    for (let i = 0; i < repeat; i++) {
        list.forEach((p, idx) => items.push({ ...p, _key: `${i}-${p.id}-${idx}` }));
    }
    return items;
});

const { t } = useI18n();
const isScrolled = ref(false);
const activeTestiIndex = ref(0);
let observer = null;
let testiTimer = null;

function getInitials(name) {
    if (!name) return 'GP';
    return name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0].toUpperCase())
        .join('');
}

function startTestiAutoPlay() {
    stopTestiAutoPlay();
    if (!props.testimonials?.length || props.testimonials.length <= 1) return;
    testiTimer = setInterval(() => {
        activeTestiIndex.value = (activeTestiIndex.value + 1) % props.testimonials.length;
    }, 4500);
}

function stopTestiAutoPlay() {
    if (testiTimer) {
        clearInterval(testiTimer);
        testiTimer = null;
    }
}

function prevTestimonial() {
    if (!props.testimonials?.length) return;
    activeTestiIndex.value = (activeTestiIndex.value - 1 + props.testimonials.length) % props.testimonials.length;
    startTestiAutoPlay();
}

function nextTestimonial() {
    if (!props.testimonials?.length) return;
    activeTestiIndex.value = (activeTestiIndex.value + 1) % props.testimonials.length;
    startTestiAutoPlay();
}

function formatFcfa(amount) {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' FCFA';
}

function onScroll() {
    isScrolled.value = window.scrollY > 40;
}

function scrollToSection(sectionId) {
    const el = document.getElementById(sectionId);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    const navCollapse = document.getElementById('mainNav');
    if (navCollapse?.classList.contains('show')) {
        window.bootstrap?.Collapse.getInstance(navCollapse)?.hide();
    }
}

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer?.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.12 }
    );

    document.querySelectorAll('.reveal-on-scroll').forEach((el) => observer.observe(el));
    startTestiAutoPlay();
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
    observer?.disconnect();
    stopTestiAutoPlay();
});
</script>

<template>
    <Head :title="t('hero.title')" />

    <!-- Hero Carousel / Slider + Navbar superposée -->
    <header class="position-relative text-white overflow-hidden">
        <!-- Navbar superposée (transparente en haut, sombre au scroll) -->
        <nav
            class="navbar navbar-expand-xl navbar-dark fixed-top navbar-overlay-transition"
            :style="isScrolled
                ? 'background: rgba(15, 23, 42, 0.94); backdrop-filter: blur(12px); box-shadow: 0 8px 24px rgba(15,23,42,0.25);'
                : 'background: linear-gradient(180deg, rgba(15, 23, 42, 0.72) 0%, rgba(15, 23, 42, 0) 100%);'"
        >
            <div class="container py-2">
                <a class="navbar-brand fw-bold d-flex align-items-center gap-2 flex-shrink-0" href="/">
                    <img
                        :src="siteLogoUrl"
                        :alt="appName"
                        class="bg-white rounded-3 px-2 py-1 shadow-sm"
                        style="height: 38px; width: auto; object-fit: contain;"
                    />
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div id="mainNav" class="collapse navbar-collapse mobile-nav-collapse">
                    <ul class="navbar-nav me-auto mb-2 mb-xl-0 ms-xl-3 gap-xl-1 align-items-xl-center">
                        <li class="nav-item">
                            <a class="nav-link text-white text-nowrap px-2 small fw-medium" href="#fonctionnalites" @click.prevent="scrollToSection('fonctionnalites')">{{ t('nav.features') }}</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-info fw-semibold text-nowrap px-2 small d-inline-flex align-items-center gap-1" href="#ia-auto" @click.prevent="scrollToSection('ia-auto')">
                                <i class="bi bi-stars"></i>
                                <span>IA Auto</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a
                                class="nav-link fw-semibold text-nowrap px-2 small d-inline-flex align-items-center gap-1"
                                href="#mode-hors-ligne"
                                style="color: #6ee7b7;"
                                @click.prevent="scrollToSection('mode-hors-ligne')"
                            >
                                <i class="bi bi-wifi-off"></i>
                                <span>Hors Ligne</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white text-nowrap px-2 small fw-medium" href="#tarifs" @click.prevent="scrollToSection('tarifs')">{{ t('nav.pricing') }}</a>
                        </li>
                        <li class="nav-item">
                            <Link class="nav-link text-warning fw-semibold text-nowrap px-2 small d-inline-flex align-items-center gap-1" href="/marketplace">
                                <i class="bi bi-shop"></i>
                                <span>Marketplace</span>
                            </Link>
                        </li>
                        <li class="nav-item">
                            <Link class="nav-link fw-semibold text-nowrap px-2 small d-inline-flex align-items-center gap-1" href="/sos-depannage" style="color: #fda4af;">
                                <i class="bi bi-geo-alt-fill"></i>
                                <span>SOS Dépannage</span>
                            </Link>
                        </li>
                    </ul>
                    <div class="d-flex flex-wrap flex-xl-nowrap align-items-center gap-2 flex-shrink-0">
                        <LanguageSwitcher variant="light" />
                        <template v-if="isAuthenticatedPro">
                            <a
                                :href="dashboardUrl"
                                class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold shadow-sm d-inline-flex align-items-center gap-2 text-nowrap"
                            >
                                <i class="bi bi-speedometer2"></i>
                                <span>Dashboard</span>
                                <span
                                    v-if="authTenantName"
                                    class="badge bg-white text-primary rounded-pill d-none d-xxl-inline text-truncate"
                                    style="font-size: 0.7rem; max-width: 130px;"
                                >
                                    {{ authTenantName }}
                                </span>
                            </a>
                        </template>
                        <template v-else-if="isDemoSession">
                            <a href="/demo/quitter?to=login" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold shadow-sm text-nowrap">{{ t('nav.login') }}</a>
                        </template>
                        <template v-else>
                            <Link href="/connexion" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold shadow-sm text-nowrap">{{ t('nav.login') }}</Link>
                        </template>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Slider / Carrousel Hero -->
        <div id="heroCarousel" class="carousel slide carousel-fade" data-bs-ride="carousel" data-bs-interval="5500">
            <div class="carousel-indicators">
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="1" aria-label="Slide 2"></button>
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="2" aria-label="Slide 3"></button>
            </div>

            <div class="carousel-inner">
                <!-- Slide 1 : Plateforme SaaS Garage -->
                <div
                    class="carousel-item active"
                    style="min-height: 680px; background: linear-gradient(125deg, rgba(15, 23, 42, 0.92) 0%, rgba(30, 58, 138, 0.80) 55%, rgba(37, 99, 235, 0.65) 100%), url('/images/hero-garage.jpg') center/cover no-repeat;"
                >
                    <div class="container py-5 d-flex align-items-center" style="min-height: 680px; padding-top: 6.5rem !important;">
                        <div class="row align-items-center g-5 w-100">
                            <div class="col-lg-7">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                    <span class="badge bg-primary bg-opacity-75 border border-light border-opacity-25 rounded-pill px-3 py-2 pulse-badge">
                                        <i class="bi bi-stars me-1"></i>{{ t('hero.badge') }}
                                    </span>
                                    <a
                                        href="#mode-hors-ligne"
                                        class="badge rounded-pill px-3 py-2 text-decoration-none d-inline-flex align-items-center gap-1 hero-offline-pill"
                                        @click.prevent="scrollToSection('mode-hors-ligne')"
                                    >
                                        <i class="bi bi-wifi-off"></i>
                                        <span>Mode Hors Ligne &amp; PWA inclus</span>
                                    </a>
                                </div>
                                <h1 class="display-4 fw-bold mb-3 lh-sm">{{ t('hero.title') }}</h1>
                                <p class="lead text-white-50 mb-4" style="max-width: 600px;">{{ t('hero.subtitle') }}</p>
                                <div class="d-flex flex-wrap gap-3">
                                    <Link href="/inscription" class="btn btn-primary btn-lg px-4 py-3 fw-semibold shadow hover-lift">
                                        {{ t('hero.ctaStart', { days: trialDays }) }}
                                    </Link>
                                    <a href="/demo" class="btn btn-warning btn-lg px-4 py-3 fw-semibold text-dark shadow hover-lift">
                                        <i class="bi bi-play-circle-fill me-2"></i>{{ t('hero.ctaDemo') }}
                                    </a>
                                </div>
                                <p class="small text-white-50 mt-3 mb-0">
                                    <i class="bi bi-shield-check me-1 text-info"></i>{{ t('hero.noCard') }}
                                </p>
                            </div>
                            <div class="col-lg-5 d-none d-lg-block">
                                <div class="card border-0 shadow-lg text-white p-4 float-slow" style="background: rgba(15, 23, 42, 0.72); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.14) !important;">
                                    <h2 class="h5 fw-bold mb-3">{{ t('features.title') }}</h2>
                                    <div class="vstack gap-3 small">
                                        <div class="d-flex align-items-start gap-3">
                                            <span class="badge bg-primary p-2"><i class="bi bi-globe fs-6"></i></span>
                                            <div>
                                                <div class="fw-semibold">{{ t('features.f5Title') }}</div>
                                                <div class="text-white-50">{{ t('features.f5Desc', { domain: baseDomain }) }}</div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-start gap-3">
                                            <span class="badge bg-success p-2"><i class="bi bi-phone fs-6"></i></span>
                                            <div>
                                                <div class="fw-semibold">{{ t('features.f2Title') }}</div>
                                                <div class="text-white-50">{{ t('features.f2Desc') }}</div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-start gap-3">
                                            <span class="badge bg-info text-dark p-2"><i class="bi bi-journal-check fs-6"></i></span>
                                            <div>
                                                <div class="fw-semibold">{{ t('features.f6Title') }}</div>
                                                <div class="text-white-50">{{ t('features.f6Desc') }}</div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-start gap-3 pt-2 border-top border-white border-opacity-10">
                                            <span class="badge bg-warning text-dark p-2"><i class="bi bi-wifi-off fs-6"></i></span>
                                            <div>
                                                <div class="fw-semibold text-warning">Mode Hors Ligne &amp; Synchro Auto</div>
                                                <div class="text-white-50">Continuez sans Internet : vos dossiers se synchronisent automatiquement dès le retour du réseau.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Slide 2 : Marketplace Pièces Automobiles -->
                <div
                    class="carousel-item"
                    style="min-height: 680px; background: linear-gradient(125deg, rgba(15, 23, 42, 0.90) 0%, rgba(16, 185, 129, 0.55) 100%), url('/images/auth-garage.jpg') center/cover no-repeat;"
                >
                    <div class="container py-5 d-flex align-items-center" style="min-height: 680px; padding-top: 6.5rem !important;">
                        <div class="col-lg-8">
                            <span class="badge bg-warning text-dark rounded-pill mb-3 px-3 py-2 fw-semibold">
                                <i class="bi bi-shop me-1"></i>Marketplace Pièces Détachées
                            </span>
                            <h2 class="display-4 fw-bold mb-3 lh-sm">Achetez &amp; vendez des pièces auto vérifiées partout en Afrique</h2>
                            <p class="lead text-white-50 mb-4" style="max-width: 620px;">
                                Freinage, moteur, suspension, filtres et carrosserie : commandez en ligne et faites-vous livrer directement par des garages professionnels.
                            </p>
                            <div class="d-flex flex-wrap gap-3">
                                <Link href="/marketplace" class="btn btn-warning btn-lg px-4 py-3 fw-semibold text-dark shadow hover-lift">
                                    <i class="bi bi-shop me-2"></i>Explorer le Marketplace
                                </Link>
                                <Link href="/inscription" class="btn btn-outline-light btn-lg px-4 py-3 fw-semibold">
                                    Vendre mes pièces
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Slide 3 : RH, Pointage & Rentabilité -->
                <div
                    class="carousel-item"
                    style="min-height: 680px; background: linear-gradient(125deg, rgba(15, 23, 42, 0.92) 0%, rgba(99, 102, 241, 0.70) 100%), url('/images/hero-garage.jpg') center/cover no-repeat;"
                >
                    <div class="container py-5 d-flex align-items-center" style="min-height: 680px; padding-top: 6.5rem !important;">
                        <div class="col-lg-8">
                            <span class="badge bg-info text-dark rounded-pill mb-3 px-3 py-2 fw-semibold">
                                <i class="bi bi-graph-up-arrow me-1"></i>Pilotage Financier &amp; RH
                            </span>
                            <h2 class="display-4 fw-bold mb-3 lh-sm">Maîtrisez vos marges, vos stocks et la paie de vos mécaniciens</h2>
                            <p class="lead text-white-50 mb-4" style="max-width: 620px;">
                                Pointage automatique, alertes de déficit mensuel, bons de commande fournisseurs par e-mail et comptabilité SYSCOHADA révisée.
                            </p>
                            <div class="d-flex flex-wrap gap-3">
                                <a href="/demo" class="btn btn-primary btn-lg px-4 py-3 fw-semibold shadow hover-lift">
                                    <i class="bi bi-play-circle-fill me-2"></i>{{ t('hero.ctaDemo') }}
                                </a>
                                <Link href="/contact" class="btn btn-outline-light btn-lg px-4 py-3 fw-semibold">
                                    {{ t('nav.contact') }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev" style="width: 5%;">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Précédent</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next" style="width: 5%;">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Suivant</span>
            </button>
        </div>
    </header>

    <!-- Bandeau Statistiques -->
    <section class="py-4 bg-white border-bottom">
        <div class="container">
            <div class="row text-center g-3 reveal-on-scroll">
                <div class="col-6 col-md-3">
                    <div class="h3 fw-bold text-primary mb-0">500+</div>
                    <div class="small text-muted">{{ t('stats.garages') }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="h3 fw-bold text-primary mb-0">45 000+</div>
                    <div class="small text-muted">{{ t('stats.repairs') }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="h3 fw-bold text-primary mb-0">99.9%</div>
                    <div class="small text-muted">{{ t('stats.uptime') }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="h3 fw-bold text-primary mb-0">24/7</div>
                    <div class="small text-muted">{{ t('stats.support') }}</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Mise en avant permanente : Mode Hors Ligne & PWA Installable -->
    <section id="mode-hors-ligne" class="offline-showcase py-5 position-relative overflow-hidden">
        <div class="container py-3 position-relative" style="z-index: 1;">
            <div class="row align-items-center g-4 g-lg-5">
                <div class="col-lg-6">
                    <span class="offline-kicker">
                        <i class="bi bi-wifi-off"></i>
                        <span>Conçu pour les réalités terrain en Afrique</span>
                    </span>
                    <h2 class="offline-title mt-3 mb-3">
                        Internet coupe ? <span class="text-emerald">Votre activité continue sans interruption.</span>
                    </h2>
                    <p class="offline-lead mb-4">
                        Avec le <strong>Mode Hors Ligne &amp; PWA</strong> de {{ appName }}, vos équipes enregistrent clients, véhicules, PV de visite technique, polices d'assurance et sinistres (photos incluses) même sans connexion. Tout se synchronise automatiquement dès le retour du réseau.
                    </p>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="offline-pillar">
                                <span class="offline-pillar-icon"><i class="bi bi-hdd-stack-fill"></i></span>
                                <div>
                                    <strong>Sauvegarde locale sécurisée</strong>
                                    <p>Vos saisies et photos restent protégées dans votre appareil tant que le réseau est coupé.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="offline-pillar">
                                <span class="offline-pillar-icon"><i class="bi bi-arrow-repeat"></i></span>
                                <div>
                                    <strong>Synchro automatique</strong>
                                    <p>Envoi automatique dans l'ordre chronologique dès qu'Internet revient, sans rien ressaisir.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="offline-pillar">
                                <span class="offline-pillar-icon"><i class="bi bi-shield-check"></i></span>
                                <div>
                                    <strong>Zéro doublon garanti</strong>
                                    <p>Identifiant unique anti-doublon et résolution intelligente des conflits multi-postes.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="offline-pillar">
                                <span class="offline-pillar-icon"><i class="bi bi-laptop"></i></span>
                                <div>
                                    <strong>Installable en 1 clic (PWA)</strong>
                                    <p>Utilisez {{ appName }} comme une vraie application sur PC Windows, tablette ou mobile.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <Link href="/inscription" class="btn btn-success btn-lg rounded-pill px-4 fw-bold shadow">
                            <i class="bi bi-rocket-takeoff-fill me-2"></i>Essayer avec le Mode Hors Ligne
                        </Link>
                        <PwaInstallButton variant="light" size="lg" label="Télécharger l'application" />
                    </div>
                </div>

                <!-- Maquette visuelle du Centre de Synchronisation -->
                <div class="col-lg-6">
                    <div class="offline-mockup-card">
                        <div class="offline-mockup-header">
                            <div class="d-flex align-items-center gap-2">
                                <span class="offline-dot-amber"></span>
                                <span class="fw-bold small text-white">Centre de Synchronisation MboaCar</span>
                            </div>
                            <span class="badge rounded-pill bg-warning text-dark fw-bold px-3 py-1">
                                <i class="bi bi-wifi-off me-1"></i>Hors ligne • 3 en attente
                            </span>
                        </div>

                        <div class="offline-mockup-body">
                            <div class="offline-queue-row">
                                <span class="offline-row-icon bg-primary-subtle text-primary"><i class="bi bi-person-plus-fill"></i></span>
                                <div class="flex-grow-1">
                                    <div class="fw-bold small text-white">Client : Samuel Nkeng (+237 691...)</div>
                                    <div class="offline-row-sub">Sauvegardé localement • anti-doublon actif</div>
                                </div>
                                <span class="badge bg-warning text-dark">En attente</span>
                            </div>

                            <div class="offline-queue-row">
                                <span class="offline-row-icon bg-success-subtle text-success"><i class="bi bi-clipboard2-check-fill"></i></span>
                                <div class="flex-grow-1">
                                    <div class="fw-bold small text-white">PV Visite Technique — LT 888 ZZ</div>
                                    <div class="offline-row-sub">Verdict : Favorable • Ligne 2</div>
                                </div>
                                <span class="badge bg-warning text-dark">En attente</span>
                            </div>

                            <div class="offline-queue-row">
                                <span class="offline-row-icon bg-danger-subtle text-danger"><i class="bi bi-shield-exclamation"></i></span>
                                <div class="flex-grow-1">
                                    <div class="fw-bold small text-white">Sinistre Assurance + 4 photos dégâts</div>
                                    <div class="offline-row-sub">Photos stockées hors ligne en haute qualité</div>
                                </div>
                                <span class="badge bg-info text-dark"><i class="bi bi-images me-1"></i>4 photos</span>
                            </div>
                        </div>

                        <div class="offline-mockup-footer">
                            <div class="d-flex align-items-center gap-2 small" style="color: #6ee7b7;">
                                <i class="bi bi-cloud-check-fill fs-5"></i>
                                <span><strong>Dès le retour d'Internet :</strong> synchronisation automatique en quelques secondes</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Fonctionnalités -->
    <section id="fonctionnalites" class="py-5">
        <div class="container py-3">
            <div class="text-center mb-5 reveal-on-scroll">
                <span class="text-primary fw-semibold small text-uppercase">{{ t('nav.features') }}</span>
                <h2 class="h2 fw-bold mt-1">{{ t('features.title') }}</h2>
                <p class="text-muted">{{ t('features.subtitle') }}</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4 reveal-on-scroll">
                    <div class="card h-100 border-0 shadow-sm p-2 hover-lift">
                        <div class="card-body">
                            <div class="bg-primary-subtle text-primary rounded-3 d-inline-flex p-3 mb-3">
                                <i class="bi bi-calendar-check fs-3"></i>
                            </div>
                            <h3 class="h5 fw-bold">{{ t('features.f1Title') }}</h3>
                            <p class="text-muted small mb-0">{{ t('features.f1Desc') }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 reveal-on-scroll">
                    <div class="card h-100 border-0 shadow-sm p-2 hover-lift">
                        <div class="card-body">
                            <div class="bg-primary-subtle text-primary rounded-3 d-inline-flex p-3 mb-3">
                                <i class="bi bi-receipt fs-3"></i>
                            </div>
                            <h3 class="h5 fw-bold">{{ t('features.f2Title') }}</h3>
                            <p class="text-muted small mb-0">{{ t('features.f2Desc') }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 reveal-on-scroll">
                    <div class="card h-100 border-0 shadow-sm p-2 hover-lift">
                        <div class="card-body">
                            <div class="bg-primary-subtle text-primary rounded-3 d-inline-flex p-3 mb-3">
                                <i class="bi bi-box-seam fs-3"></i>
                            </div>
                            <h3 class="h5 fw-bold">{{ t('features.f3Title') }}</h3>
                            <p class="text-muted small mb-0">{{ t('features.f3Desc') }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 reveal-on-scroll">
                    <div class="card h-100 border-0 shadow-sm p-2 hover-lift">
                        <div class="card-body">
                            <div class="bg-primary-subtle text-primary rounded-3 d-inline-flex p-3 mb-3">
                                <i class="bi bi-person-badge fs-3"></i>
                            </div>
                            <h3 class="h5 fw-bold">{{ t('features.f4Title') }}</h3>
                            <p class="text-muted small mb-0">{{ t('features.f4Desc') }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 reveal-on-scroll">
                    <div class="card h-100 border-0 shadow-sm p-2 hover-lift">
                        <div class="card-body">
                            <div class="bg-primary-subtle text-primary rounded-3 d-inline-flex p-3 mb-3">
                                <i class="bi bi-building fs-3"></i>
                            </div>
                            <h3 class="h5 fw-bold">{{ t('features.f5Title') }}</h3>
                            <p class="text-muted small mb-0">{{ t('features.f5Desc', { domain: baseDomain }) }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 reveal-on-scroll">
                    <div class="card h-100 border-0 shadow-sm p-2 hover-lift">
                        <div class="card-body">
                            <div class="bg-primary-subtle text-primary rounded-3 d-inline-flex p-3 mb-3">
                                <i class="bi bi-graph-up-arrow fs-3"></i>
                            </div>
                            <h3 class="h5 fw-bold">{{ t('features.f6Title') }}</h3>
                            <p class="text-muted small mb-0">{{ t('features.f6Desc') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Showcase MboaCar IA Auto avec simulation animée en boucle -->
    <LandingAiShowcase />

    <!-- Tarifs -->
    <section id="tarifs" class="pricing-section py-5 border-top position-relative overflow-hidden">
        <div class="pricing-glow pricing-glow-1" aria-hidden="true"></div>
        <div class="pricing-glow pricing-glow-2" aria-hidden="true"></div>

        <div class="container py-4 position-relative" style="z-index: 1;">
            <div class="text-center mx-auto mb-5 reveal-on-scroll" style="max-width: 640px;">
                <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fw-semibold small text-uppercase mb-2">
                    <i class="bi bi-tag-fill me-1"></i>{{ t('nav.pricing') }}
                </span>
                <h2 class="display-6 fw-bold text-dark mt-1 mb-2">{{ t('pricing.title') }}</h2>
                <p class="text-muted mb-0">{{ t('pricing.subtitle', { days: trialDays }) }}</p>
            </div>

            <div class="pricing-card-shell mx-auto reveal-on-scroll">
                <div class="row g-0">
                    <!-- Panneau gauche : Tarif & CTA -->
                    <div class="col-lg-5 pricing-left-panel text-white p-4 p-md-5 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                                <span class="badge rounded-pill bg-primary px-3 py-2 fw-semibold">
                                    <i class="bi bi-stars me-1"></i>{{ t('pricing.planName') }}
                                </span>
                                <span class="badge rounded-pill bg-white bg-opacity-10 text-white border border-white border-opacity-25 px-2 py-1" style="font-size: 0.72rem;">
                                    Tout inclus
                                </span>
                            </div>

                            <div class="d-flex align-items-baseline flex-wrap gap-2 mb-2">
                                <span class="pricing-amount fw-bold lh-1">{{ formatFcfa(monthlyPrice) }}</span>
                                <span class="text-white-50 fs-6">{{ t('pricing.perMonth') }}</span>
                            </div>

                            <div class="d-inline-flex align-items-center gap-2 rounded-3 px-3 py-2 mt-2 mb-4 pricing-trial-pill small">
                                <i class="bi bi-gift-fill text-warning"></i>
                                <span>{{ t('pricing.trialNote', { days: trialDays }) }}</span>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mb-4">
                                <span class="pricing-space-chip"><i class="bi bi-wrench-adjustable me-1"></i>Garages</span>
                                <span class="pricing-space-chip"><i class="bi bi-clipboard2-check me-1"></i>Visite Technique</span>
                                <span class="pricing-space-chip"><i class="bi bi-shield-check me-1"></i>Assurances</span>
                                <span class="pricing-space-chip" style="border-color: rgba(110, 231, 183, 0.45); color: #6ee7b7;"><i class="bi bi-wifi-off me-1"></i>Mode Hors Ligne &amp; PWA</span>
                            </div>
                        </div>

                        <div>
                            <a
                                v-if="isAuthenticatedPro"
                                :href="dashboardUrl"
                                class="btn btn-light btn-lg w-100 py-3 fw-bold text-primary rounded-4 shadow pricing-cta-btn d-flex align-items-center justify-content-center gap-2"
                            >
                                <i class="bi bi-speedometer2"></i>
                                <span>Accéder à mon Dashboard</span>
                            </a>
                            <a
                                v-else-if="isDemoSession"
                                href="/demo/quitter?to=register"
                                class="btn btn-primary btn-lg w-100 py-3 fw-bold rounded-4 shadow pricing-cta-btn d-flex align-items-center justify-content-center gap-2"
                            >
                                <span>{{ t('pricing.cta') }}</span>
                                <i class="bi bi-arrow-right"></i>
                            </a>
                            <Link
                                v-else
                                href="/inscription"
                                class="btn btn-primary btn-lg w-100 py-3 fw-bold rounded-4 shadow pricing-cta-btn d-flex align-items-center justify-content-center gap-2"
                            >
                                <span>{{ t('pricing.cta') }}</span>
                                <i class="bi bi-arrow-right"></i>
                            </Link>

                            <div class="text-center mt-3">
                                <a href="/demo" class="text-white-50 small text-decoration-none pricing-demo-link">
                                    <i class="bi bi-play-circle me-1"></i>Ou tester la démo interactive gratuitement
                                </a>
                            </div>

                            <div class="d-flex justify-content-center gap-3 mt-3 pt-3 border-top border-white border-opacity-10 text-white-50" style="font-size: 0.75rem;">
                                <span><i class="bi bi-shield-lock-fill text-success me-1"></i>Sans carte bancaire</span>
                                <span><i class="bi bi-x-circle text-info me-1"></i>Sans engagement</span>
                            </div>
                        </div>
                    </div>

                    <!-- Panneau droit : Tout ce qui est inclus -->
                    <div class="col-lg-7 bg-white p-4 p-md-5 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                <h3 class="h5 fw-bold text-dark mb-0">Tout est inclus dès le 1er jour</h3>
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 small">
                                    <i class="bi bi-check-circle-fill me-1"></i>0 frais cachés
                                </span>
                            </div>
                            <p class="text-muted small mb-4">
                                Aucune option payante supplémentaire : profitez de l'intégralité de la plateforme pour digitaliser votre activité.
                            </p>

                            <div class="row g-3 mb-4">
                                <div class="col-sm-6">
                                    <div class="pricing-feature-item">
                                        <span class="pricing-check-icon"><i class="bi bi-check-lg"></i></span>
                                        <span>{{ t('pricing.p1') }}</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="pricing-feature-item">
                                        <span class="pricing-check-icon"><i class="bi bi-check-lg"></i></span>
                                        <span>{{ t('pricing.p2') }}</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="pricing-feature-item">
                                        <span class="pricing-check-icon"><i class="bi bi-check-lg"></i></span>
                                        <span>{{ t('pricing.p3') }}</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="pricing-feature-item">
                                        <span class="pricing-check-icon"><i class="bi bi-check-lg"></i></span>
                                        <span>{{ t('pricing.p4') }}</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="pricing-feature-item">
                                        <span class="pricing-check-icon"><i class="bi bi-check-lg"></i></span>
                                        <span>{{ t('pricing.p5') }}</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="pricing-feature-item">
                                        <span class="pricing-check-icon"><i class="bi bi-check-lg"></i></span>
                                        <span>{{ t('pricing.p6') }}</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="pricing-feature-item">
                                        <span class="pricing-check-icon pricing-check-ai"><i class="bi bi-stars"></i></span>
                                        <span><strong>MboaCar IA Auto</strong> (Diagnostic &amp; Devis)</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="pricing-feature-item">
                                        <span class="pricing-check-icon"><i class="bi bi-check-lg"></i></span>
                                        <span>Marketplace Pièces &amp; Portail Client</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pricing-trust-bar rounded-4 p-3 d-flex flex-wrap align-items-center justify-content-between gap-2 small">
                            <div class="d-flex align-items-center gap-2 text-dark fw-semibold">
                                <i class="bi bi-phone-fill text-primary"></i>
                                <span>Paiement Mobile Money (OM / MTN) &amp; Carte</span>
                            </div>
                            <div class="text-muted">
                                <i class="bi bi-headset text-success me-1"></i>Support dédié 7j/7
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Témoignages (gérés depuis l'espace Admin, inspiré du design empilé) -->
    <section
        v-if="testimonials?.length"
        id="temoignages"
        class="py-5 border-top overflow-hidden"
        style="background: radial-gradient(circle at 85% 30%, rgba(59, 130, 246, 0.10), transparent 55%), linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);"
    >
        <div class="container py-4">
            <div class="row align-items-center g-5 reveal-on-scroll">
                <!-- Colonne gauche : présentation + avatars -->
                <div class="col-lg-5">
                    <span class="badge bg-white text-dark border shadow-sm rounded-pill px-3 py-2 mb-3 d-inline-flex align-items-center gap-2">
                        <span class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 22px; height: 22px;">
                            <i class="bi bi-chat-quote-fill" style="font-size: 0.7rem;"></i>
                        </span>
                        <span class="small fw-semibold">{{ t('testimonials.badge') }}</span>
                    </span>
                    <h2 class="display-6 fw-bold mb-3 lh-sm">
                        {{ t('testimonials.title1') }}<br />
                        <span class="text-primary">{{ t('testimonials.title2') }}</span>
                    </h2>
                    <p class="text-muted mb-4">{{ t('testimonials.subtitle') }}</p>

                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="d-flex align-items-center">
                            <span
                                v-for="(item, idx) in testimonials.slice(0, 4)"
                                :key="item.id"
                                class="rounded-circle fw-bold text-white d-inline-flex align-items-center justify-content-center avatar-stack-item shadow-sm"
                                :class="idx % 2 === 0 ? 'bg-dark' : 'bg-primary'"
                            >
                                {{ getInitials(item.author_name) }}
                            </span>
                        </div>
                        <span class="small text-muted text-truncate">
                            {{ testimonials.slice(0, 3).map((i) => i.author_name).join(', ') }}...
                        </span>
                    </div>

                    <Link href="/inscription" class="btn btn-outline-primary rounded-3 px-4 py-2 fw-semibold">
                        {{ t('testimonials.cta') }} <i class="bi bi-chevron-right ms-1 small"></i>
                    </Link>
                </div>

                <!-- Colonne droite : cartes empilées interactives (défilement auto + pause au survol) -->
                <div
                    class="col-lg-7"
                    @mouseenter="stopTestiAutoPlay"
                    @mouseleave="startTestiAutoPlay"
                >
                    <div class="testimonial-stack mx-auto" style="max-width: 580px;">
                        <div v-if="testimonials.length > 2" class="testimonial-card-back testimonial-card-back-2"></div>
                        <div v-if="testimonials.length > 1" class="testimonial-card-back testimonial-card-back-1"></div>

                        <div class="testimonial-card-active p-4 p-md-5">
                            <Transition name="testi-fade" mode="out-in">
                                <div :key="testimonials[activeTestiIndex].id">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="text-warning">
                                            <i v-for="n in testimonials[activeTestiIndex].rating" :key="n" class="bi bi-star-fill me-1"></i>
                                        </div>
                                        <span class="text-primary opacity-25 fw-bold lh-1" style="font-size: 3.5rem; font-family: Georgia, serif;">&ldquo;</span>
                                    </div>

                                    <p class="fs-6 text-dark lh-lg mb-4" style="min-height: 110px;">
                                        « {{ testimonials[activeTestiIndex].quote }} »
                                    </p>

                                    <div class="d-flex align-items-center gap-3 pt-2">
                                        <span
                                            class="rounded-circle bg-dark text-white fw-bold d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                            style="width: 44px; height: 44px;"
                                        >
                                            {{ getInitials(testimonials[activeTestiIndex].author_name) }}
                                        </span>
                                        <div>
                                            <div class="fw-bold text-dark">{{ testimonials[activeTestiIndex].author_name }}</div>
                                            <div class="small text-muted">
                                                {{ testimonials[activeTestiIndex].author_role }} — {{ testimonials[activeTestiIndex].garage_name }}
                                                {{ testimonials[activeTestiIndex].city ? `(${testimonials[activeTestiIndex].city})` : '' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </Transition>
                        </div>
                    </div>

                    <!-- Contrôles & barre de progression -->
                    <div v-if="testimonials.length > 1" class="d-flex flex-column align-items-center mt-4">
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <button
                                type="button"
                                class="btn btn-white border rounded-circle shadow-sm d-inline-flex align-items-center justify-content-center"
                                style="width: 38px; height: 38px;"
                                @click="prevTestimonial"
                            >
                                <i class="bi bi-arrow-left"></i>
                            </button>
                            <span class="small fw-semibold text-muted">
                                <span class="text-dark">{{ activeTestiIndex + 1 }}</span> / {{ testimonials.length }}
                            </span>
                            <button
                                type="button"
                                class="btn btn-white border rounded-circle shadow-sm d-inline-flex align-items-center justify-content-center"
                                style="width: 38px; height: 38px;"
                                @click="nextTestimonial"
                            >
                                <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                        <div class="progress" style="width: 140px; height: 4px;">
                            <div
                                class="progress-bar bg-primary"
                                role="progressbar"
                                :style="{ width: (((activeTestiIndex + 1) / testimonials.length) * 100) + '%' }"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section id="faq" class="py-5 bg-white border-top">
        <div class="container py-3 reveal-on-scroll">
            <h2 class="h3 fw-bold text-center mb-4">{{ t('faq.title') }}</h2>
            <div id="faqAccordion" class="accordion mx-auto shadow-sm" style="max-width: 760px;">
                <div class="accordion-item">
                    <h3 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            {{ t('faq.q1') }}
                        </button>
                    </h3>
                    <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                        <div class="accordion-body text-muted small">{{ t('faq.a1') }}</div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            {{ t('faq.q2') }}
                        </button>
                    </h3>
                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body text-muted small">{{ t('faq.a2') }}</div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            {{ t('faq.q3') }}
                        </button>
                    </h3>
                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body text-muted small">{{ t('faq.a3') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pied de page pro (Landing) -->
    <!-- Nos Partenaires (défilement fluide, pause au survol, masqué si aucun partenaire) -->
    <section v-if="partners && partners.length > 0" class="py-4 bg-light border-top overflow-hidden partners-section">
        <div class="container mb-3 text-center">
            <span class="text-uppercase text-muted fw-bold small" style="letter-spacing: 0.08em;">
                Ils nous font confiance — Nos partenaires
            </span>
        </div>
        <div class="partners-marquee" aria-label="Logos de nos partenaires">
            <div class="partners-marquee-track">
                <div class="partners-marquee-group">
                    <template v-for="partner in marqueePartners" :key="'a-' + partner._key">
                        <a
                            v-if="partner.website_url"
                            :href="partner.website_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="partner-logo-card"
                            :title="partner.name"
                        >
                            <img :src="partner.logo_url" :alt="partner.name" loading="lazy" />
                        </a>
                        <div v-else class="partner-logo-card" :title="partner.name">
                            <img :src="partner.logo_url" :alt="partner.name" loading="lazy" />
                        </div>
                    </template>
                </div>
                <div class="partners-marquee-group" aria-hidden="true">
                    <template v-for="partner in marqueePartners" :key="'b-' + partner._key">
                        <a
                            v-if="partner.website_url"
                            :href="partner.website_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="partner-logo-card"
                            :title="partner.name"
                        >
                            <img :src="partner.logo_url" :alt="partner.name" loading="lazy" />
                        </a>
                        <div v-else class="partner-logo-card" :title="partner.name">
                            <img :src="partner.logo_url" :alt="partner.name" loading="lazy" />
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </section>

    <LandingFooter :app-name="appName" :trial-days="trialDays" />

    <BackToTop />
</template>

<style scoped>
.pricing-section {
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 55%, #f1f5f9 100%);
}

.pricing-glow {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    pointer-events: none;
    z-index: 0;
}

.pricing-glow-1 {
    width: 340px;
    height: 340px;
    top: -90px;
    left: 10%;
    background: rgba(37, 99, 235, 0.14);
}

.pricing-glow-2 {
    width: 300px;
    height: 300px;
    bottom: -90px;
    right: 10%;
    background: rgba(14, 165, 233, 0.12);
}

.pricing-card-shell {
    max-width: 980px;
    border-radius: 26px;
    overflow: hidden;
    border: 1px solid rgba(37, 99, 235, 0.18);
    box-shadow: 0 24px 60px -15px rgba(15, 23, 42, 0.16);
    background: #ffffff;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.pricing-card-shell:hover {
    transform: translateY(-4px);
    box-shadow: 0 30px 70px -15px rgba(37, 99, 235, 0.22);
}

.pricing-left-panel {
    background: linear-gradient(155deg, #0f172a 0%, #172554 55%, #1d4ed8 100%);
    position: relative;
}

.pricing-amount {
    font-size: clamp(2.1rem, 3.6vw, 2.85rem);
    letter-spacing: -0.02em;
    color: #ffffff;
}

.pricing-trial-pill {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.18);
    color: #f8fafc;
}

.pricing-space-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 0.7rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 500;
    color: #e2e8f0;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.14);
}

.pricing-cta-btn {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.pricing-cta-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(37, 99, 235, 0.35) !important;
}

.pricing-demo-link:hover {
    color: #ffffff !important;
    text-decoration: underline !important;
}

.pricing-feature-item {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    font-size: 0.875rem;
    color: #334155;
    line-height: 1.4;
}

.pricing-check-icon {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #dcfce7;
    color: #16a34a;
    font-size: 0.8rem;
    margin-top: 1px;
}

.pricing-check-ai {
    background: #dbeafe;
    color: #2563eb;
}

.pricing-trust-bar {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.partners-marquee {
    position: relative;
    width: 100%;
    overflow: hidden;
    mask-image: linear-gradient(to right, transparent, black 8%, black 92%, transparent);
    -webkit-mask-image: linear-gradient(to right, transparent, black 8%, black 92%, transparent);
}

.partners-marquee-track {
    display: flex;
    width: max-content;
    animation: partners-scroll 28s linear infinite;
}

.partners-section:hover .partners-marquee-track,
.partners-marquee:hover .partners-marquee-track {
    animation-play-state: paused;
}

.partners-marquee-group {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding-right: 1.5rem;
}

.partner-logo-card {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.65rem 1.25rem;
    min-width: 140px;
    height: 68px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.partner-logo-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.12);
    border-color: #93c5fd;
}

.partner-logo-card img {
    max-height: 42px;
    max-width: 120px;
    width: auto;
    object-fit: contain;
    filter: grayscale(15%);
    transition: filter 0.2s ease;
}

.partner-logo-card:hover img {
    filter: grayscale(0%);
}

@keyframes partners-scroll {
    0% {
        transform: translateX(0);
    }
    100% {
        transform: translateX(-50%);
    }
}

/* Mode Hors Ligne & PWA Showcase */
.hero-offline-pill {
    background: rgba(16, 185, 129, 0.2);
    border: 1px solid rgba(110, 231, 183, 0.45);
    color: #a7f3d0;
    font-weight: 700;
    transition: background 0.18s ease, transform 0.15s ease;
}

.hero-offline-pill:hover {
    background: rgba(16, 185, 129, 0.34);
    color: #ffffff;
    transform: translateY(-1px);
}

.offline-showcase {
    background:
        radial-gradient(circle at 15% 20%, rgba(16, 185, 129, 0.2), transparent 46%),
        radial-gradient(circle at 85% 80%, rgba(37, 99, 235, 0.24), transparent 50%),
        linear-gradient(135deg, #0f172a 0%, #111c33 55%, #0f172a 100%);
    color: #f8fafc;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.offline-kicker {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.9rem;
    border-radius: 999px;
    background: rgba(16, 185, 129, 0.16);
    border: 1px solid rgba(110, 231, 183, 0.35);
    color: #6ee7b7;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.offline-title {
    font-size: clamp(1.75rem, 3vw, 2.35rem);
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -0.025em;
    color: #ffffff;
}

.text-emerald {
    color: #6ee7b7;
}

.offline-lead {
    color: #cbd5e1;
    font-size: 0.98rem;
    line-height: 1.6;
}

.offline-pillar {
    display: flex;
    align-items: flex-start;
    gap: 0.8rem;
    padding: 0.9rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.09);
    height: 100%;
}

.offline-pillar-icon {
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    border-radius: 10px;
    background: rgba(16, 185, 129, 0.18);
    border: 1px solid rgba(110, 231, 183, 0.3);
    color: #6ee7b7;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    line-height: 1;
}

.offline-pillar strong {
    display: block;
    font-size: 0.88rem;
    color: #ffffff;
    margin-bottom: 0.2rem;
}

.offline-pillar p {
    margin: 0;
    font-size: 0.78rem;
    color: #94a3b8;
    line-height: 1.45;
}

.offline-mockup-card {
    background: rgba(15, 23, 42, 0.85);
    border: 1px solid rgba(148, 163, 184, 0.22);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
}

.offline-mockup-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.65rem;
    padding: 1rem 1.25rem;
    background: rgba(255, 255, 255, 0.04);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.offline-dot-amber {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #fbbf24;
    box-shadow: 0 0 10px rgba(251, 191, 36, 0.8);
    display: inline-block;
}

.offline-mockup-body {
    padding: 1.15rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.offline-queue-row {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 0.8rem 0.9rem;
    border-radius: 13px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.offline-row-icon {
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    line-height: 1;
}

.offline-row-sub {
    font-size: 0.76rem;
    color: #94a3b8;
}

.offline-mockup-footer {
    padding: 0.9rem 1.25rem;
    background: rgba(16, 185, 129, 0.12);
    border-top: 1px solid rgba(110, 231, 183, 0.22);
}
</style>
