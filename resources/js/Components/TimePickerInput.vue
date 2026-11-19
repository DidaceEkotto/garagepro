<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    required: { type: Boolean, default: false },
    inputClass: { type: String, default: 'form-select' },
});

const emit = defineEmits(['update:modelValue']);

// Créneaux de 06:00 à 22:00 par tranches de 15 min (compatible jQuery UI widget styling)
const slots = computed(() => {
    const list = [];
    for (let h = 6; h <= 22; h++) {
        for (let m = 0; m < 60; m += 15) {
            const hh = String(h).padStart(2, '0');
            const mm = String(m).padStart(2, '0');
            list.push(`${hh}:${mm}`);
        }
    }
    return list;
});
</script>

<template>
    <div class="input-group">
        <span class="input-group-text ui-widget"><i class="bi bi-clock"></i></span>
        <select
            :value="modelValue"
            :class="inputClass"
            :required="required"
            @change="emit('update:modelValue', $event.target.value)"
        >
            <option value="">--:--</option>
            <option v-for="t in slots" :key="t" :value="t">{{ t }}</option>
        </select>
    </div>
</template>
