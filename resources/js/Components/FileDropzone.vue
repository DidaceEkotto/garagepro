<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: [Object, Array, File, null], default: null },
    multiple: { type: Boolean, default: false },
    accept: { type: String, default: 'image/jpeg,image/png,image/webp' },
    label: { type: String, default: 'Glissez-déposez un fichier ici ou cliquez pour parcourir' },
});

const emit = defineEmits(['update:modelValue']);

const inputRef = ref(null);
const isDragging = ref(false);
const previews = ref([]);

function buildPreviews(files) {
    previews.value.forEach((p) => {
        if (p.url) URL.revokeObjectURL(p.url);
    });
    previews.value = [];

    const list = Array.isArray(files) ? files : files ? [files] : [];
    list.forEach((file) => {
        if (file instanceof File) {
            const isImage = file.type?.startsWith('image/');
            previews.value.push({
                name: file.name,
                sizeKb: Math.max(1, Math.round(file.size / 1024)),
                isImage,
                ext: file.name.split('.').pop()?.toUpperCase() || 'FILE',
                url: isImage ? URL.createObjectURL(file) : null,
            });
        }
    });
}

watch(() => props.modelValue, (val) => buildPreviews(val), { immediate: true });

onBeforeUnmount(() => {
    previews.value.forEach((p) => {
        if (p.url) URL.revokeObjectURL(p.url);
    });
});

function handleFiles(fileList) {
    const arr = Array.from(fileList || []);
    if (!arr.length) return;
    if (props.multiple) {
        const existing = Array.isArray(props.modelValue) ? props.modelValue : [];
        emit('update:modelValue', [...existing, ...arr]);
    } else {
        emit('update:modelValue', arr[0]);
    }
    if (inputRef.value) inputRef.value.value = '';
}

function onDrop(e) {
    isDragging.value = false;
    handleFiles(e.dataTransfer?.files);
}

function removeAt(idx) {
    if (props.multiple && Array.isArray(props.modelValue)) {
        const copy = [...props.modelValue];
        copy.splice(idx, 1);
        emit('update:modelValue', copy);
    } else {
        emit('update:modelValue', null);
    }
    if (inputRef.value) inputRef.value.value = '';
}
</script>

<template>
    <div>
        <div
            class="border rounded-3 p-3 text-center bg-light"
            :class="isDragging ? 'border-primary bg-primary-subtle' : 'border-secondary-subtle'"
            style="border-style: dashed !important; cursor: pointer;"
            @click="inputRef?.click()"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop"
        >
            <input
                ref="inputRef"
                type="file"
                class="d-none"
                :multiple="multiple"
                :accept="accept"
                @change="handleFiles($event.target.files)"
            />
            <i class="bi bi-cloud-arrow-up fs-3 text-primary d-block mb-1"></i>
            <div class="small text-muted">{{ label }}</div>
        </div>

        <div v-if="previews.length" class="d-flex flex-wrap gap-2 mt-2">
            <div v-for="(p, idx) in previews" :key="`${p.name}-${idx}`" class="position-relative border rounded p-1 bg-white">
                <img v-if="p.isImage && p.url" :src="p.url" :alt="p.name" class="rounded" style="height: 64px; width: 64px; object-fit: cover;" />
                <div v-else class="d-flex align-items-center gap-2 px-2 py-1 pe-4" style="max-width: 190px;">
                    <i class="bi bi-file-earmark-text fs-4 text-primary"></i>
                    <div class="text-truncate">
                        <div class="small fw-semibold text-truncate" style="font-size: 0.75rem;">{{ p.name }}</div>
                        <div class="text-muted" style="font-size: 0.68rem;">{{ p.ext }} • {{ p.sizeKb }} Ko</div>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn btn-danger btn-sm position-absolute top-0 end-0 p-0 d-flex align-items-center justify-content-center"
                    style="width: 20px; height: 20px; border-radius: 50%;"
                    @click.stop="removeAt(idx)"
                >
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
</template>
