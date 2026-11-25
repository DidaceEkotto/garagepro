import { computed, ref } from 'vue';

const isOpen = ref(false);
const images = ref([]);
const currentIndex = ref(0);
const title = ref('');

export function useLightbox() {
    function openLightbox(list, startIndex = 0, heading = '') {
        const normalized = (Array.isArray(list) ? list : [list])
            .filter(Boolean)
            .map((item) => (typeof item === 'string' ? { url: item, label: '' } : { url: item.url, label: item.label ?? '' }));

        if (!normalized.length) return;

        images.value = normalized;
        currentIndex.value = Math.max(0, Math.min(startIndex, normalized.length - 1));
        title.value = heading || '';
        isOpen.value = true;
    }

    function closeLightbox() {
        isOpen.value = false;
    }

    function nextImage() {
        if (!images.value.length) return;
        currentIndex.value = (currentIndex.value + 1) % images.value.length;
    }

    function prevImage() {
        if (!images.value.length) return;
        currentIndex.value = (currentIndex.value - 1 + images.value.length) % images.value.length;
    }

    function goToImage(idx) {
        if (idx >= 0 && idx < images.value.length) {
            currentIndex.value = idx;
        }
    }

    const currentImage = computed(() => images.value[currentIndex.value] ?? null);

    return {
        isOpen,
        images,
        currentIndex,
        currentImage,
        title,
        openLightbox,
        closeLightbox,
        nextImage,
        prevImage,
        goToImage,
    };
}
