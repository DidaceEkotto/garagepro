<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from '../composables/useI18n';

const { t } = useI18n();
const visible = ref(false);

function handleScroll() {
    visible.value = window.scrollY > 300;
}

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

onMounted(() => {
    window.addEventListener('scroll', handleScroll, { passive: true });
    handleScroll();
});

onUnmounted(() => {
    window.removeEventListener('scroll', handleScroll);
});
</script>

<template>
    <transition name="fade-scale">
        <button
            v-if="visible"
            type="button"
            class="btn btn-primary rounded-circle shadow-lg back-to-top-btn d-flex align-items-center justify-content-center"
            :title="t('layout.backToTop')"
            :aria-label="t('layout.backToTop')"
            @click="scrollToTop"
        >
            <i class="bi bi-arrow-up fs-5"></i>
        </button>
    </transition>
</template>
