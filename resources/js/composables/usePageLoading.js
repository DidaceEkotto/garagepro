import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const isNavigating = ref(false);
const isAnyRequest = ref(false);
let initialized = false;
let showTimer = null;

export function initPageLoadingListener() {
    if (initialized) return;
    initialized = true;

    router.on('start', (event) => {
        isAnyRequest.value = true;
        const method = (event?.detail?.visit?.method || 'get').toLowerCase();
        if (method !== 'get') return;

        if (showTimer) clearTimeout(showTimer);
        showTimer = setTimeout(() => {
            isNavigating.value = true;
        }, 75);
    });

    const stop = () => {
        if (showTimer) {
            clearTimeout(showTimer);
            showTimer = null;
        }
        isAnyRequest.value = false;
        isNavigating.value = false;
    };

    router.on('finish', stop);
    router.on('error', stop);
    router.on('invalid', stop);
}

export function usePageLoading() {
    initPageLoadingListener();

    return {
        isNavigating,
        isAnyRequest,
    };
}
