<script setup>
import $ from 'jquery';
import 'jquery-ui-dist/jquery-ui.min.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'AAAA-MM-JJ' },
    required: { type: Boolean, default: false },
    inputClass: { type: String, default: 'form-control' },
});

const emit = defineEmits(['update:modelValue', 'change']);
const el = ref(null);
let initialized = false;

async function ensureDatepicker() {
    window.$ = window.jQuery = $;
    if (!$.fn?.datepicker) {
        const mod = await import('jquery-ui-dist/jquery-ui.min.js');
        if (!$.fn?.datepicker && typeof mod?.default === 'function') {
            try {
                mod.default($);
            } catch {}
        }
    }
    const jq = window.jQuery || $;
    if (!initialized && jq?.fn?.datepicker && el.value) {
        jq(el.value).datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            yearRange: '1940:2035',
            dayNamesMin: ['Di', 'Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa'],
            monthNamesShort: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'],
            beforeShow: (_input, inst) => {
                setTimeout(() => {
                    if (inst?.dpDiv) {
                        inst.dpDiv.css('z-index', 2060);
                    }
                }, 0);
            },
            onSelect: (dateText) => {
                emit('update:modelValue', dateText);
                emit('change', dateText);
            },
        });
        initialized = true;
    }
}

async function openPicker() {
    await ensureDatepicker();
    const jq = window.jQuery || $;
    if (jq?.fn?.datepicker && el.value) {
        jq(el.value).datepicker('show');
    }
}

onMounted(() => {
    ensureDatepicker();
});

watch(
    () => props.modelValue,
    (val) => {
        if (el.value && el.value.value !== (val ?? '')) {
            el.value.value = val ?? '';
        }
    }
);

onBeforeUnmount(() => {
    const jq = window.jQuery || $;
    if (initialized && jq?.fn?.datepicker && el.value) {
        try {
            jq(el.value).datepicker('destroy');
        } catch {}
    }
});
</script>

<template>
    <div class="input-group">
        <span class="input-group-text" style="cursor: pointer;" @click="openPicker"><i class="bi bi-calendar3"></i></span>
        <input
            ref="el"
            type="text"
            :value="modelValue"
            :class="inputClass"
            :placeholder="placeholder"
            :required="required"
            autocomplete="off"
            @click="openPicker"
            @focus="openPicker"
            @input="emit('update:modelValue', $event.target.value); emit('change', $event.target.value)"
        />
    </div>
</template>
