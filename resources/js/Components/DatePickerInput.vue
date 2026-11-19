<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'AAAA-MM-JJ' },
    required: { type: Boolean, default: false },
    inputClass: { type: String, default: 'form-control' },
});

const emit = defineEmits(['update:modelValue', 'change']);
const el = ref(null);

onMounted(async () => {
    const $ = window.jQuery;
    if ($ && ! $.fn?.datepicker) {
        await import('jquery-ui-dist/jquery-ui.min.js');
    }
    if ($ && $.fn && $.fn.datepicker && el.value) {
        $(el.value).datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            yearRange: '1940:2035',
            dayNamesMin: ['Di', 'Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa'],
            monthNamesShort: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'],
            onSelect: (dateText) => {
                emit('update:modelValue', dateText);
                emit('change', dateText);
            },
        });
    }
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
    const $ = window.jQuery;
    if ($ && $.fn && $.fn.datepicker && el.value) {
        try {
            $(el.value).datepicker('destroy');
        } catch {}
    }
});
</script>

<template>
    <div class="input-group">
        <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
        <input
            ref="el"
            type="text"
            :value="modelValue"
            :class="inputClass"
            :placeholder="placeholder"
            :required="required"
            autocomplete="off"
            @input="emit('update:modelValue', $event.target.value); emit('change', $event.target.value)"
        />
    </div>
</template>
