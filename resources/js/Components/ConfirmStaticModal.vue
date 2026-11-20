<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import { useConfirm } from '@/composables/useConfirm';

const { confirmState, executeConfirm } = useConfirm();
</script>

<template>
    <StaticModal id="globalConfirmStaticModal" :title="confirmState.title">
        <p class="mb-4 text-dark">{{ confirmState.message }}</p>
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
            <button type="button" class="btn px-4" :class="confirmState.confirmClass" @click="executeConfirm">
                {{ confirmState.confirmLabel }}
            </button>
        </div>
    </StaticModal>
</template>
