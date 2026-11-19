<script setup>
import BackToTop from '@/Components/BackToTop.vue';
import LandingFooter from '@/Components/LandingFooter.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    title: { type: String, required: true },
    slug: { type: String, default: 'terms' },
    content: { type: String, default: '' },
});

const { t } = useI18n();
const page = usePage();
const appName = computed(() => {
    const name = page.props.app?.site?.name || page.props.app?.name || 'MboaCar';
    return name === 'Laravel' ? 'MboaCar' : name;
});
const logoUrl = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');
</script>

<template>
    <Head :title="`${title} — ${appName}`" />

    <div class="min-vh-100 d-flex flex-column bg-light">
        <nav class="navbar navbar-expand-lg navbar-dark sticky-top shadow-sm" style="background: rgba(15, 23, 42, 0.96); backdrop-filter: blur(10px); z-index: 1030;">
            <div class="container py-1">
                <Link class="navbar-brand fw-bold d-flex align-items-center gap-2" href="/">
                    <img
                        :src="logoUrl"
                        :alt="appName"
                        class="bg-white rounded-3 px-2 py-1 shadow-sm"
                        style="height: 38px; width: auto; object-fit: contain;"
                    />
                </Link>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <Link href="/" class="btn btn-outline-light btn-sm rounded-pill px-3">{{ t('mp.home') }}</Link>
                    <Link href="/marketplace" class="btn btn-outline-warning btn-sm rounded-pill px-3">
                        <i class="bi bi-shop me-1"></i>{{ t('mp.marketplace') }}
                    </Link>
                    <LanguageSwitcher variant="light" />
                </div>
            </div>
        </nav>

        <main class="flex-grow-1 py-5">
            <div class="container" style="max-width: 900px;">
                <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1">
                            <i class="bi bi-file-earmark-text me-1"></i>Document légal
                        </span>
                    </div>
                    <h1 class="h2 fw-bold text-dark mb-4 pb-3 border-bottom">{{ title }}</h1>
                    <div class="legal-rich-content text-dark" style="line-height: 1.75;" v-html="content"></div>
                </div>
            </div>
        </main>

        <LandingFooter :app-name="appName" />
        <BackToTop />
    </div>
</template>

<style scoped>
.legal-rich-content :deep(blockquote) {
    border-left: 4px solid #2563eb;
    padding: 0.75rem 1rem;
    background-color: #f8fafc;
    color: #334155;
    margin: 1rem 0;
    border-radius: 0.375rem;
}
.legal-rich-content :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0;
}
.legal-rich-content :deep(th),
.legal-rich-content :deep(td) {
    border: 1px solid #cbd5e1;
    padding: 0.5rem 0.75rem;
}
.legal-rich-content :deep(th) {
    background-color: #f1f5f9;
    font-weight: 600;
}
</style>
