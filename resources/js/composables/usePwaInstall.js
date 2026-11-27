import { computed, ref } from 'vue';

const deferredPrompt = ref(null);
const isInstalled = ref(false);
const isIos = ref(false);
let listenerAttached = false;

export function initPwaInstallListener() {
    if (listenerAttached || typeof window === 'undefined') {
        return;
    }
    listenerAttached = true;

    const ua = window.navigator.userAgent || '';
    isIos.value = /iphone|ipad|ipod/i.test(ua);

    const checkStandalone = () => {
        const mediaMatch = window.matchMedia?.('(display-mode: standalone)')?.matches;
        const iosStandalone = window.navigator.standalone === true;
        isInstalled.value = Boolean(mediaMatch || iosStandalone);
    };

    checkStandalone();

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt.value = event;
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt.value = null;
        isInstalled.value = true;
    });
}

export function usePwaInstall() {
    initPwaInstallListener();

    const canNativeInstall = computed(() => Boolean(deferredPrompt.value));
    const showInstallButton = computed(() => !isInstalled.value);

    async function triggerInstall() {
        if (!deferredPrompt.value) {
            return 'manual';
        }

        deferredPrompt.value.prompt();
        const choice = await deferredPrompt.value.userChoice;
        deferredPrompt.value = null;

        if (choice?.outcome === 'accepted') {
            isInstalled.value = true;
            return 'accepted';
        }

        return 'dismissed';
    }

    return {
        isInstalled,
        isIos,
        canNativeInstall,
        showInstallButton,
        triggerInstall,
    };
}
