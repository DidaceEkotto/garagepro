<script setup>
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

defineProps({
    variant: { type: String, default: 'table' }, // 'table' | 'marketplace'
});
</script>

<template>
    <div class="mboacar-skeleton-wrapper py-2" aria-busy="true" :aria-label="t('layoutExtra.loading')">
        <!-- En-tête -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div style="min-width: 240px;">
                <div class="mboacar-skeleton-box mb-2" style="height: 24px; width: 220px;"></div>
                <div class="mboacar-skeleton-box" style="height: 14px; width: 320px; max-width: 100%;"></div>
            </div>
            <div class="d-flex gap-2">
                <div class="mboacar-skeleton-box" style="height: 36px; width: 140px;"></div>
                <div class="mboacar-skeleton-box" style="height: 36px; width: 120px;"></div>
            </div>
        </div>

        <!-- Variante Marketplace : grille de produits -->
        <div v-if="variant === 'marketplace'" class="container py-2">
            <div class="row g-3">
                <div v-for="i in 8" :key="i" class="col-6 col-md-4 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 p-2">
                        <div class="mboacar-skeleton-box mb-3" style="height: 150px; width: 100%;"></div>
                        <div class="mboacar-skeleton-box mb-2" style="height: 16px; width: 85%;"></div>
                        <div class="mboacar-skeleton-box mb-3" style="height: 14px; width: 55%;"></div>
                        <div class="mboacar-skeleton-box mt-auto" style="height: 34px; width: 100%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Variante ERP : KPIs + Tableau -->
        <template v-else>
            <div class="row g-3 mb-4">
                <div v-for="k in 4" :key="k" class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm p-3">
                        <div class="mboacar-skeleton-box mb-2" style="height: 13px; width: 60%;"></div>
                        <div class="mboacar-skeleton-box" style="height: 26px; width: 45%;"></div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="mboacar-skeleton-box" style="height: 32px; width: 220px;"></div>
                    <div class="mboacar-skeleton-box" style="height: 32px; width: 180px;"></div>
                </div>
                <div v-for="r in 6" :key="r" class="d-flex align-items-center gap-3 py-2 border-top">
                    <div class="mboacar-skeleton-box" style="height: 16px; width: 22%;"></div>
                    <div class="mboacar-skeleton-box" style="height: 16px; width: 25%;"></div>
                    <div class="mboacar-skeleton-box" style="height: 16px; width: 18%;"></div>
                    <div class="mboacar-skeleton-box ms-auto" style="height: 26px; width: 90px;"></div>
                </div>
            </div>
        </template>
    </div>
</template>
