<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from '../composables/useI18n';

const props = defineProps({
    variant: {
        type: String,
        default: 'dark', // 'dark' | 'light'
    },
});

const { locale, supportedLocales } = useI18n();

const currentLocale = computed(() => {
    return (
        supportedLocales.value.find((item) => item.code === locale.value) || {
            code: 'fr',
            label: 'Français',
            flag: '🇫🇷',
        }
    );
});

function selectLocale(code) {
    if (code === locale.value) return;
    router.post(`/langue/${code}`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="dropdown">
        <button
            class="btn btn-sm dropdown-toggle d-inline-flex align-items-center gap-2 rounded-pill px-3"
            :class="variant === 'light' ? 'btn-outline-light' : 'btn-outline-secondary bg-white text-dark border'"
            type="button"
            data-bs-toggle="dropdown"
            aria-expanded="false"
        >
            <span class="fs-6 lh-1">{{ currentLocale.flag }}</span>
            <span class="fw-medium small">{{ currentLocale.label }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 p-1" style="min-width: 170px; z-index: 1040;">
            <li v-for="item in supportedLocales" :key="item.code">
                <button
                    type="button"
                    class="dropdown-item rounded-2 d-flex align-items-center justify-content-between py-2 px-3"
                    :class="{ 'active fw-semibold': item.code === locale }"
                    @click="selectLocale(item.code)"
                >
                    <span class="d-flex align-items-center gap-2">
                        <span class="fs-5 lh-1">{{ item.flag }}</span>
                        <span>{{ item.label }}</span>
                    </span>
                    <i v-if="item.code === locale" class="bi bi-check2 small"></i>
                </button>
            </li>
        </ul>
    </div>
</template>
