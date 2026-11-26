<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    dialCode: { type: String, default: '' },
    placeholder: { type: String, default: '6XX XXX XXX' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    isInvalid: { type: Boolean, default: false },
    size: { type: String, default: '' }, // '' | 'sm' | 'lg'
});

const emit = defineEmits(['update:modelValue']);

const page = usePage();
const resolvedDialCode = computed(() => {
    const code = (props.dialCode || page.props.tenant?.dialCode || '+237').trim();
    return code.startsWith('+') ? code : `+${code}`;
});

function stripDialPrefix(raw) {
    const str = String(raw ?? '').trim();
    if (!str) return '';
    const dialDigits = resolvedDialCode.value.replace(/\D/g, '');
    const escapedDial = resolvedDialCode.value.replace('+', '\\+');
    const regex = new RegExp(`^(?:${escapedDial}|00${dialDigits}|${dialDigits})[\\s\\-]*`);
    if (str.startsWith('+') || str.startsWith('00')) {
        return str.replace(regex, '').trim();
    }
    return str;
}

const localNumber = computed({
    get() {
        return stripDialPrefix(props.modelValue);
    },
    set(val) {
        const cleaned = stripDialPrefix(val);
        if (!cleaned) {
            emit('update:modelValue', '');
            return;
        }
        emit('update:modelValue', `${resolvedDialCode.value} ${cleaned}`);
    },
});

const groupClass = computed(() => (props.size ? `input-group input-group-${props.size}` : 'input-group'));
</script>

<template>
    <div :class="groupClass">
        <span class="input-group-text fw-semibold bg-light text-dark user-select-none">
            {{ resolvedDialCode }}
        </span>
        <input
            v-model="localNumber"
            type="tel"
            class="form-control"
            :class="{ 'is-invalid': isInvalid }"
            :placeholder="placeholder"
            :required="required"
            :disabled="disabled"
        />
    </div>
</template>
