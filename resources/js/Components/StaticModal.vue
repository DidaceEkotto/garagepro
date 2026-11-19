<script setup>
defineProps({
    id: { type: String, required: true },
    title: { type: String, required: true },
    size: { type: String, default: '' }, // '', 'modal-lg', 'modal-xl'
});
</script>

<template>
    <div
        :id="id"
        class="modal fade"
        tabindex="-1"
        data-bs-backdrop="static"
        data-bs-keyboard="false"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered" :class="size">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-white border-bottom py-3">
                    <h2 class="modal-title h6 fw-bold mb-0">{{ title }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body p-4">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
