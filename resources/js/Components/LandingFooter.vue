<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    appName: { type: String, default: '' },
    trialDays: { type: Number, default: 7 },
});

const { t } = useI18n();
const page = usePage();
const resolvedAppName = computed(() => props.appName || page.props.app?.name || 'MboaCar');
const site = computed(() => page.props.app?.site || {});
</script>

<template>
    <footer class="text-white" style="background-color: #0f172a;">
        <!-- Bandeau de confiance -->
        <div class="border-bottom border-secondary border-opacity-25" style="background-color: #0b1120;">
            <div class="container py-4">
                <div class="row g-3 text-center text-md-start">
                    <div class="col-6 col-lg-3">
                        <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                            <span class="rounded-circle bg-primary bg-opacity-25 text-warning d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                                <i class="bi bi-shield-check fs-5"></i>
                            </span>
                            <div>
                                <div class="fw-semibold small text-white">{{ t('features.f1Title') }}</div>
                                <div class="text-white-50" style="font-size: 0.76rem;">100% Sécurisé &amp; Multi-agences</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                            <span class="rounded-circle bg-primary bg-opacity-25 text-warning d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                                <i class="bi bi-receipt-cutoff fs-5"></i>
                            </span>
                            <div>
                                <div class="fw-semibold small text-white">OHADA / SYSCOHADA</div>
                                <div class="text-white-50" style="font-size: 0.76rem;">Devis, factures &amp; comptabilité</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                            <span class="rounded-circle bg-primary bg-opacity-25 text-warning d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                                <i class="bi bi-shop fs-5"></i>
                            </span>
                            <div>
                                <div class="fw-semibold small text-white">{{ t('mp.marketplace') }}</div>
                                <div class="text-white-50" style="font-size: 0.76rem;">Pièces auto en Afrique</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                            <span class="rounded-circle bg-primary bg-opacity-25 text-warning d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                                <i class="bi bi-headset fs-5"></i>
                            </span>
                            <div>
                                <div class="fw-semibold small text-white">{{ t('stats.support') }}</div>
                                <div class="text-white-50" style="font-size: 0.76rem;">WhatsApp &amp; Email 7j/7</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container py-5">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="fw-bold fs-5 mb-3 d-flex align-items-center gap-2">
                        <img
                            :src="site?.logo_url || '/assets/images/logo.png'"
                            :alt="resolvedAppName"
                            class="bg-white rounded-3 px-2 py-1 shadow-sm"
                            style="height: 40px; width: auto; object-fit: contain;"
                        />
                    </div>
                    <p class="small text-white-50 mb-3" style="line-height: 1.65;">{{ t('footer.tagline') }}</p>
                    <div class="small text-white-50 vstack gap-1 mb-3">
                        <div v-if="site.whatsapp"><i class="bi bi-whatsapp text-success me-2"></i>{{ site.whatsapp }}</div>
                        <div v-if="site.email"><i class="bi bi-envelope text-warning me-2"></i>{{ site.email }}</div>
                        <div v-if="site.secondary_email"><i class="bi bi-envelope-at text-info me-2"></i>{{ site.secondary_email }}</div>
                        <div v-if="site.po_box"><i class="bi bi-mailbox text-primary me-2"></i>{{ site.po_box }}</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a v-if="site.facebook" :href="site.facebook" target="_blank" rel="noopener noreferrer" class="btn btn-outline-light btn-sm rounded-circle" style="width: 32px; height: 32px; padding: 4px;"><i class="bi bi-facebook"></i></a>
                        <a v-if="site.linkedin" :href="site.linkedin" target="_blank" rel="noopener noreferrer" class="btn btn-outline-light btn-sm rounded-circle" style="width: 32px; height: 32px; padding: 4px;"><i class="bi bi-linkedin"></i></a>
                        <a v-if="site.twitter" :href="site.twitter" target="_blank" rel="noopener noreferrer" class="btn btn-outline-light btn-sm rounded-circle" style="width: 32px; height: 32px; padding: 4px;"><i class="bi bi-twitter-x"></i></a>
                        <span class="badge bg-dark border border-secondary text-white-50 px-2 py-1">FCFA (XAF)</span>
                        <span class="badge bg-dark border border-secondary text-white-50 px-2 py-1">SYSCOHADA</span>
                        <span class="badge bg-dark border border-secondary text-white-50 px-2 py-1">SaSPay</span>
                    </div>
                </div>

                <div class="col-6 col-lg-2 offset-lg-1">
                    <h4 class="h6 fw-bold mb-3">{{ t('footer.product') }}</h4>
                    <ul class="list-unstyled small vstack gap-2 mb-0">
                        <li><Link href="/#fonctionnalites" class="text-white-50 text-decoration-none">{{ t('nav.features') }}</Link></li>
                        <li><Link href="/#mode-hors-ligne" class="text-decoration-none fw-semibold" style="color: #6ee7b7;"><i class="bi bi-wifi-off me-1"></i>Mode Hors Ligne</Link></li>
                        <li><Link href="/#tarifs" class="text-white-50 text-decoration-none">{{ t('nav.pricing') }}</Link></li>
                        <li><Link href="/marketplace" class="text-white-50 text-decoration-none">{{ t('mp.marketplace') }}</Link></li>
                        <li><Link href="/#faq" class="text-white-50 text-decoration-none">{{ t('nav.faq') }}</Link></li>
                    </ul>
                </div>

                <div class="col-6 col-lg-2">
                    <h4 class="h6 fw-bold mb-3">{{ t('footer.legal') }}</h4>
                    <ul class="list-unstyled small vstack gap-2 mb-0">
                        <li><Link href="/inscription" class="text-white-50 text-decoration-none">{{ t('nav.register') }}</Link></li>
                        <li><Link href="/connexion" class="text-white-50 text-decoration-none">{{ t('nav.login') }}</Link></li>
                        <li><Link href="/contact" class="text-white-50 text-decoration-none">{{ t('nav.contact') }}</Link></li>
                        <li><Link href="/conditions-utilisation" class="text-white-50 text-decoration-none">{{ t('footer.terms') }}</Link></li>
                        <li><Link href="/politique-confidentialite" class="text-white-50 text-decoration-none">{{ t('footer.privacy') }}</Link></li>
                        <li><a href="/demo" class="text-warning text-decoration-none fw-semibold">{{ t('nav.demo') }}</a></li>
                    </ul>
                </div>

                <div class="col-lg-3">
                    <h4 class="h6 fw-bold mb-3">{{ t('footer.contact') }}</h4>
                    <p class="small text-white-50 mb-3">
                        {{ t('pricing.trialNote', { days: trialDays }) }}
                    </p>
                    <div class="d-grid gap-2">
                        <Link href="/inscription" class="btn btn-warning btn-sm fw-semibold text-dark">
                            <i class="bi bi-rocket-takeoff me-1"></i>{{ t('pricing.cta') }}
                        </Link>
                        <Link href="/contact" class="btn btn-outline-light btn-sm">
                            <i class="bi bi-envelope me-1"></i>{{ t('nav.contact') }}
                        </Link>
                    </div>
                </div>
            </div>

            <hr class="border-secondary border-opacity-25 my-4" />

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 small text-white-50">
                <span>&copy; {{ new Date().getFullYear() }} {{ resolvedAppName }}. {{ t('footer.rights') }}</span>
                <LanguageSwitcher variant="light" />
            </div>
        </div>
    </footer>
</template>
