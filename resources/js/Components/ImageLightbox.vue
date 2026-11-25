<script setup>
import { useLightbox } from '@/composables/useLightbox';
import { computed, onBeforeUnmount, onMounted } from 'vue';

const { isOpen, images, currentIndex, currentImage, title, closeLightbox, nextImage, prevImage, goToImage } = useLightbox();

const downloadFilename = computed(() => {
    if (!currentImage.value?.url) return 'photo.jpg';
    const parts = currentImage.value.url.split('?')[0].split('/');
    return parts[parts.length - 1] || `photo-${currentIndex.value + 1}.jpg`;
});

function handleKeydown(e) {
    if (!isOpen.value) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        closeLightbox();
    } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        nextImage();
    } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        prevImage();
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="isOpen && currentImage"
            class="gp-lightbox-overlay"
            role="dialog"
            aria-modal="true"
            @click.self="closeLightbox"
        >
            <!-- Top toolbar -->
            <div class="gp-lightbox-topbar">
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-white bg-opacity-25 text-white px-3 py-2 fw-semibold" style="font-size: 0.85rem;">
                        <i class="bi bi-images me-1"></i>
                        {{ currentIndex + 1 }} / {{ images.length }}
                    </span>
                    <span v-if="title || currentImage.label" class="text-white small fw-medium text-truncate" style="max-width: 45vw;">
                        {{ currentImage.label || title }}
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a
                        :href="currentImage.url"
                        :download="downloadFilename"
                        class="btn btn-light btn-sm fw-semibold d-inline-flex align-items-center gap-1"
                        title="Télécharger cette photo"
                    >
                        <i class="bi bi-download"></i>
                        <span class="d-none d-sm-inline">Télécharger</span>
                    </a>
                    <button
                        type="button"
                        class="btn btn-outline-light btn-sm d-inline-flex align-items-center justify-content-center"
                        style="width: 34px; height: 34px;"
                        title="Fermer (Échap)"
                        @click="closeLightbox"
                    >
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>

            <!-- Main stage -->
            <div class="gp-lightbox-stage" @click.self="closeLightbox">
                <button
                    v-if="images.length > 1"
                    type="button"
                    class="gp-lightbox-nav gp-lightbox-prev"
                    title="Photo précédente (←)"
                    @click.stop="prevImage"
                >
                    <i class="bi bi-chevron-left"></i>
                </button>

                <img
                    :key="currentImage.url"
                    :src="currentImage.url"
                    :alt="currentImage.label || `Photo ${currentIndex + 1}`"
                    class="gp-lightbox-img"
                />

                <button
                    v-if="images.length > 1"
                    type="button"
                    class="gp-lightbox-nav gp-lightbox-next"
                    title="Photo suivante (→)"
                    @click.stop="nextImage"
                >
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>

            <!-- Thumbnail strip -->
            <div v-if="images.length > 1" class="gp-lightbox-thumbs">
                <button
                    v-for="(img, idx) in images"
                    :key="`${img.url}-${idx}`"
                    type="button"
                    class="gp-lightbox-thumb"
                    :class="{ active: idx === currentIndex }"
                    @click="goToImage(idx)"
                >
                    <img :src="img.url" :alt="`Miniature ${idx + 1}`" />
                </button>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.gp-lightbox-overlay {
    position: fixed;
    inset: 0;
    z-index: 3000;
    background: rgba(15, 23, 42, 0.92);
    backdrop-filter: blur(6px);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    user-select: none;
}

.gp-lightbox-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.25rem;
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.55) 0%, rgba(0, 0, 0, 0) 100%);
}

.gp-lightbox-stage {
    position: relative;
    flex: 1;
    min-height: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 3.75rem;
}

.gp-lightbox-img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    border-radius: 0.5rem;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.55);
}

.gp-lightbox-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 44px;
    height: 44px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    background: rgba(15, 23, 42, 0.65);
    color: #fff;
    font-size: 1.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.15s ease;
}

.gp-lightbox-nav:hover {
    background: rgba(37, 99, 235, 0.9);
    transform: translateY(-50%) scale(1.06);
}

.gp-lightbox-prev {
    left: 1rem;
}

.gp-lightbox-next {
    right: 1rem;
}

.gp-lightbox-thumbs {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem 1rem;
    overflow-x: auto;
}

.gp-lightbox-thumb {
    width: 56px;
    height: 42px;
    padding: 0;
    border-radius: 0.375rem;
    overflow: hidden;
    border: 2px solid transparent;
    background: transparent;
    opacity: 0.6;
    cursor: pointer;
    flex-shrink: 0;
    transition: opacity 0.15s ease, border-color 0.15s ease;
}

.gp-lightbox-thumb.active,
.gp-lightbox-thumb:hover {
    opacity: 1;
    border-color: #3b82f6;
}

.gp-lightbox-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
</style>
