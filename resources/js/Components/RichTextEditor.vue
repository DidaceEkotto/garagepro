<script setup>
import { onMounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Décrivez la pièce en détail (état, compatibilité, garantie...)' },
});

const emit = defineEmits(['update:modelValue']);
const editorRef = ref(null);
let isInternalUpdate = false;

onMounted(() => {
    if (editorRef.value && props.modelValue) {
        editorRef.value.innerHTML = props.modelValue;
    }
});

watch(
    () => props.modelValue,
    (val) => {
        if (isInternalUpdate) {
            isInternalUpdate = false;
            return;
        }
        if (editorRef.value && editorRef.value.innerHTML !== (val || '')) {
            editorRef.value.innerHTML = val || '';
        }
    }
);

function onInput() {
    if (!editorRef.value) return;
    isInternalUpdate = true;
    emit('update:modelValue', editorRef.value.innerHTML);
}

function exec(command, value = null) {
    editorRef.value?.focus();
    document.execCommand(command, false, value);
    onInput();
}
</script>

<template>
    <div class="border rounded-3 overflow-hidden bg-white">
        <div class="bg-light border-bottom px-2 py-1 d-flex flex-wrap gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Gras" @click="exec('bold')">
                <i class="bi bi-type-bold"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Italique" @click="exec('italic')">
                <i class="bi bi-type-italic"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Souligné" @click="exec('underline')">
                <i class="bi bi-type-underline"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Liste à puces" @click="exec('insertUnorderedList')">
                <i class="bi bi-list-ul"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Liste numérotée" @click="exec('insertOrderedList')">
                <i class="bi bi-list-ol"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Effacer la mise en forme" @click="exec('removeFormat')">
                <i class="bi bi-eraser"></i>
            </button>
        </div>
        <div
            ref="editorRef"
            contenteditable="true"
            class="p-3 small"
            style="min-height: 130px; outline: none;"
            :data-placeholder="placeholder"
            @input="onInput"
        ></div>
    </div>
</template>
