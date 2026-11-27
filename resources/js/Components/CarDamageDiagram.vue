<script setup>
import { ref } from 'vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
const activeType = ref('rayure');

const types = [
    { key: 'rayure', label: 'Rayure', color: '#f59e0b' },
    { key: 'bosse', label: 'Bosse / Choc', color: '#dc2626' },
    { key: 'bris', label: 'Bris / Vitre', color: '#7c3aed' },
    { key: 'peinture', label: 'Éclat peinture', color: '#2563eb' },
];

function colorFor(type) {
    return types.find((t) => t.key === type)?.color || '#dc2626';
}

function onSvgClick(e) {
    if (props.readonly) return;
    const svg = e.currentTarget;
    const rect = svg.getBoundingClientRect();
    const x = Math.round(((e.clientX - rect.left) / rect.width) * 100);
    const y = Math.round(((e.clientY - rect.top) / rect.height) * 100);
    const next = [...(props.modelValue || []), { x, y, type: activeType.value }];
    emit('update:modelValue', next);
}

function removeMarker(idx) {
    if (props.readonly) return;
    const next = [...(props.modelValue || [])];
    next.splice(idx, 1);
    emit('update:modelValue', next);
}
</script>

<template>
    <div class="border rounded-3 p-2 bg-light">
        <div v-if="!readonly" class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <span class="small fw-semibold text-muted"><i class="bi bi-car-front me-1"></i>Cliquez sur la carrosserie pour marquer un défaut :</span>
            <div class="btn-group btn-group-sm">
                <button
                    v-for="t in types"
                    :key="t.key"
                    type="button"
                    class="btn"
                    :class="activeType === t.key ? 'btn-dark' : 'btn-outline-secondary'"
                    @click="activeType = t.key"
                >
                    <span class="d-inline-block rounded-circle me-1" :style="{ width: '8px', height: '8px', background: t.color }"></span>{{ t.label }}
                </button>
            </div>
        </div>

        <div class="position-relative mx-auto" style="max-width: 360px;">
            <svg
                viewBox="0 0 360 160"
                class="w-100 rounded bg-white border"
                :style="{ cursor: readonly ? 'default' : 'crosshair' }"
                @click="onSvgClick"
            >
                <!-- Silhouette vue de dessus d'un véhicule -->
                <rect x="28" y="34" width="304" height="92" rx="28" fill="#f8fafc" stroke="#334155" stroke-width="2.5" />
                <!-- Pare-brise avant & lunette arrière -->
                <path d="M 96 42 L 122 46 L 122 114 L 96 118 Z" fill="#dbeafe" stroke="#64748b" stroke-width="1.5" />
                <path d="M 244 46 L 268 42 L 268 118 L 244 114 Z" fill="#dbeafe" stroke="#64748b" stroke-width="1.5" />
                <!-- Toit -->
                <rect x="122" y="46" width="122" height="68" rx="8" fill="#f1f5f9" stroke="#64748b" stroke-width="1.5" />
                <!-- Roues -->
                <rect x="66" y="24" width="44" height="10" rx="4" fill="#1e293b" />
                <rect x="250" y="24" width="44" height="10" rx="4" fill="#1e293b" />
                <rect x="66" y="126" width="44" height="10" rx="4" fill="#1e293b" />
                <rect x="250" y="126" width="44" height="10" rx="4" fill="#1e293b" />
                <!-- Phares avant (gauche = AVANT) -->
                <text x="12" y="84" font-size="9" fill="#64748b" font-weight="bold">AV</text>
                <text x="336" y="84" font-size="9" fill="#64748b" font-weight="bold">AR</text>

                <!-- Marqueurs de dégâts -->
                <g
                    v-for="(m, idx) in modelValue"
                    :key="idx"
                    :transform="`translate(${(m.x / 100) * 360}, ${(m.y / 100) * 160})`"
                    style="cursor: pointer;"
                    @click.stop="removeMarker(idx)"
                >
                    <circle r="9" :fill="colorFor(m.type)" stroke="#fff" stroke-width="2" />
                    <text y="3.5" text-anchor="middle" font-size="9" fill="#fff" font-weight="bold">{{ idx + 1 }}</text>
                </g>
            </svg>
        </div>

        <div v-if="modelValue?.length" class="d-flex flex-wrap gap-1 mt-2">
            <span
                v-for="(m, idx) in modelValue"
                :key="idx"
                class="badge text-white d-inline-flex align-items-center gap-1"
                :style="{ background: colorFor(m.type) }"
            >
                #{{ idx + 1 }} {{ m.type }}
                <button v-if="!readonly" type="button" class="btn-close btn-close-white" style="font-size: 0.55rem;" @click="removeMarker(idx)"></button>
            </span>
        </div>
    </div>
</template>
