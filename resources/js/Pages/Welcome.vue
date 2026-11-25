<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import LandingAiShowcase from '../Components/LandingAiShowcase.vue';
import LandingFooter from '../Components/LandingFooter.vue';
import BackToTop from '../Components/BackToTop.vue';
import { useI18n } from '../composables/useI18n';

const props = defineProps({
    appName: { type: String, default: 'MboaCar' },
    trialDays: { type: Number, default: 7 },
    monthlyPrice: { type: Number, default: 10000 },
    baseDomain: { type: String, default: 'mboacar.com' },
    testimonials: { type: Array, default: () => [] },
});

const page = usePage();
const siteLogoUrl = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');

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
            class="navbar navbar-expand-lg navbar-dark fixed-top navbar-overlay-transition"
            :style="isScrolled
                ? 'background: rgba(15, 23, 42, 0.94); backdrop-filter: blur(12px); box-shadow: 0 8px 24px rgba(15,23,42,0.25);'
                : 'background: linear-gradient(180deg, rgba(15, 23, 42, 0.72) 0%, rgba(15, 23, 42, 0) 100%);'"
        >
            <div class="container py-2">
                <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="/">
                    <img
                        :src="siteLogoUrl"
                        :alt="appName"
                        class="bg-white rounded-3 px-2 py-1 shadow-sm"
                        style="height: 40px; width: auto; object-fit: contain;"
                    />
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div id="mainNav" class="collapse navbar-collapse mobile-nav-collapse">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-lg-2">
                        <li class="nav-item"><a class="nav-link text-white" href="#fonctionnalites" @click.prevent="scrollToSection('fonctionnalites')">{{ t('nav.features') }}</a></li>
                        <li class="nav-item">
                            <a class="nav-link text-info fw-semibold" href="#ia-auto" @click.prevent="scrollToSection('ia-auto')">
                                <i class="bi bi-stars me-1"></i>IA Auto
                            </a>
                        </li>
                        <li class="nav-item"><a class="nav-link text-white" href="#tarifs" @click.prevent="scrollToSection('tarifs')">{{ t('nav.pricing') }}</a></li>
                        <li class="nav-item">
                            <Link class="nav-link text-warning fw-semibold" href="/marketplace">
                                <i class="bi bi-shop me-1"></i>Marketplace
                            </Link>
                        </li>
                        <li class="nav-item"><Link class="nav-link text-white" href="/contact">{{ t('nav.contact') }}</Link></li>
                    </ul>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <LanguageSwitcher variant="light" />
                        <Link href="/connexion" class="btn btn-outline-light btn-sm rounded-pill px-3">{{ t('nav.login') }}</Link>
                        <Link href="/inscription" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold shadow-sm">
                            {{ t('nav.register') }}
                        </Link>
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
                                <span class="badge bg-primary bg-opacity-75 border border-light border-opacity-25 rounded-pill mb-3 px-3 py-2 pulse-badge">
                                    <i class="bi bi-stars me-1"></i>{{ t('hero.badge') }}
                                </span>
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
    <section id="tarifs" class="py-5 bg-white border-top">
        <div class="container py-3">
            <div class="text-center mb-5 reveal-on-scroll">
                <span class="text-primary fw-semibold small text-uppercase">{{ t('nav.pricing') }}</span>
                <h2 class="h2 fw-bold mt-1">{{ t('pricing.title') }}</h2>
                <p class="text-muted">{{ t('pricing.subtitle', { days: trialDays }) }}</p>
            </div>
            <div class="row justify-content-center g-4 reveal-on-scroll">
                <div class="col-md-6 col-lg-5">
                    <div class="card h-100 border-primary shadow-lg p-2 hover-lift">
                        <div class="card-body p-4 d-flex flex-column">
                            <span class="badge bg-primary align-self-start mb-2">{{ t('pricing.planName') }}</span>
                            <div class="display-5 fw-bold my-3 text-primary">
                                {{ formatFcfa(monthlyPrice) }} <span class="fs-6 text-muted fw-normal">{{ t('pricing.perMonth') }}</span>
                            </div>
                            <p class="text-muted small">{{ t('pricing.trialNote', { days: trialDays }) }}</p>
                            <ul class="list-unstyled small vstack gap-2 mb-4">
                                <li><i class="bi bi-check-lg text-success me-2"></i>{{ t('pricing.p1') }}</li>
                                <li><i class="bi bi-check-lg text-success me-2"></i>{{ t('pricing.p2') }}</li>
                                <li><i class="bi bi-check-lg text-success me-2"></i>{{ t('pricing.p3') }}</li>
                                <li><i class="bi bi-check-lg text-success me-2"></i>{{ t('pricing.p4') }}</li>
                                <li><i class="bi bi-check-lg text-success me-2"></i>{{ t('pricing.p5') }}</li>
                                <li><i class="bi bi-check-lg text-success me-2"></i>{{ t('pricing.p6') }}</li>
                            </ul>
                            <Link href="/inscription" class="btn btn-primary btn-lg py-2 mt-auto fw-semibold shadow-sm">
                                {{ t('pricing.cta') }}
                            </Link>
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
    <LandingFooter :app-name="appName" :trial-days="trialDays" />

    <BackToTop />
</template>
