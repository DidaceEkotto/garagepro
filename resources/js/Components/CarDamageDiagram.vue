<script setup>
import { ref } from 'vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
const activeType = ref('rayure');

const types = [
    { key: 'rayure', label: 'Rayure', color: '#f59e0b', bg: '#fef3c7', text: '#92400e' },
    { key: 'bosse', label: 'Bosse / Choc', color: '#dc2626', bg: '#fee2e2', text: '#991b1b' },
    { key: 'bris', label: 'Bris / Vitre', color: '#7c3aed', bg: '#ede9fe', text: '#5b21b6' },
    { key: 'peinture', label: 'Éclat peinture', color: '#2563eb', bg: '#dbeafe', text: '#1e40af' },
];

function metaFor(type) {
    return types.find((t) => t.key === type) || types[1];
}

function zoneLabel(x, y) {
    const col = x < 26 ? 'Avant' : x > 74 ? 'Arrière' : 'Latéral';
    const row = y < 32 ? 'Gauche' : y > 68 ? 'Droit' : 'Centre / Toit';
    if (col === 'Avant' && row === 'Centre / Toit') return 'Capot / Pare-chocs AV';
    if (col === 'Arrière' && row === 'Centre / Toit') return 'Coffre / Pare-chocs AR';
    if (col === 'Latéral' && row === 'Centre / Toit') return 'Toit / Pavillon';
    return `${col} ${row}`;
}

function onSvgClick(e) {
    if (props.readonly) return;
    const svg = e.currentTarget;
    const rect = svg.getBoundingClientRect();
    const x = Math.round(((e.clientX - rect.left) / rect.width) * 100);
    const y = Math.round(((e.clientY - rect.top) / rect.height) * 100);
    const next = [...(props.modelValue || []), { x, y, type: activeType.value, zone: zoneLabel(x, y) }];
    emit('update:modelValue', next);
}

function removeMarker(idx) {
    if (props.readonly) return;
    const next = [...(props.modelValue || [])];
    next.splice(idx, 1);
    emit('update:modelValue', next);
}

function clearAll() {
    if (props.readonly) return;
    emit('update:modelValue', []);
}
</script>

<template>
    <div class="car-diagram-shell rounded-4 p-3 border bg-white">
        <div v-if="!readonly" class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="small fw-semibold text-dark d-flex align-items-center gap-2">
                <span class="badge bg-primary-subtle text-primary rounded-pill px-2 py-1">
                    <i class="bi bi-crosshair me-1"></i>Mode marquage
                </span>
                <span class="text-muted fw-normal">Cliquez directement sur la carrosserie</span>
            </div>
            <div class="d-flex flex-wrap gap-1">
                <button
                    v-for="t in types"
                    :key="t.key"
                    type="button"
                    class="btn btn-sm rounded-pill px-3 fw-semibold d-inline-flex align-items-center gap-1"
                    :class="activeType === t.key ? 'btn-dark shadow-sm' : 'btn-light border text-dark'"
                    @click="activeType = t.key"
                >
                    <span class="d-inline-block rounded-circle" :style="{ width: '9px', height: '9px', background: t.color }"></span>
                    <span>{{ t.label }}</span>
                </button>
            </div>
        </div>

        <!-- Schéma automobile haute précision (vue de dessus réaliste) -->
        <div class="car-svg-stage position-relative rounded-4 border p-2 p-md-3" style="background: radial-gradient(circle at 50% 50%, #f8fafc 0%, #eef2f6 100%);">
            <svg
                viewBox="0 0 600 280"
                class="w-100 d-block mx-auto"
                style="max-width: 580px; user-select: none;"
                :style="{ cursor: readonly ? 'default' : 'crosshair' }"
                @click="onSvgClick"
            >
                <defs>
                    <linearGradient id="carBodyGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#ffffff" />
                        <stop offset="50%" stop-color="#f1f5f9" />
                        <stop offset="100%" stop-color="#e2e8f0" />
                    </linearGradient>
                    <linearGradient id="carGlassGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stop-color="#bfdbfe" />
                        <stop offset="50%" stop-color="#93c5fd" />
                        <stop offset="100%" stop-color="#dbeafe" />
                    </linearGradient>
                    <linearGradient id="carRoofGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#f8fafc" />
                        <stop offset="100%" stop-color="#e2e8f0" />
                    </linearGradient>
                    <filter id="carDropShadow" x="-10%" y="-10%" width="120%" height="125%">
                        <feDropShadow dx="0" dy="6" stdDeviation="6" flood-color="#0f172a" flood-opacity="0.14" />
                    </filter>
                </defs>

                <!-- Repères d'orientation -->
                <text x="18" y="145" font-size="11" fill="#64748b" font-weight="700" letter-spacing="1">AVANT</text>
                <text x="536" y="145" font-size="11" fill="#64748b" font-weight="700" letter-spacing="1">ARRIÈRE</text>
                <text x="275" y="20" font-size="10" fill="#94a3b8" font-weight="600" letter-spacing="1">CÔTÉ GAUCHE</text>
                <text x="278" y="272" font-size="10" fill="#94a3b8" font-weight="600" letter-spacing="1">CÔTÉ DROIT</text>

                <!-- Roues réalistes avec relief -->
                <rect x="132" y="42" width="76" height="16" rx="7" fill="#0f172a" />
                <rect x="392" y="42" width="76" height="16" rx="7" fill="#0f172a" />
                <rect x="132" y="222" width="76" height="16" rx="7" fill="#0f172a" />
                <rect x="392" y="222" width="76" height="16" rx="7" fill="#0f172a" />

                <!-- Rétroviseurs latéraux -->
                <path d="M 218 52 C 210 38, 226 34, 236 46 L 234 55 Z" fill="#e2e8f0" stroke="#334155" stroke-width="1.8" />
                <path d="M 218 228 C 210 242, 226 246, 236 234 L 234 225 Z" fill="#e2e8f0" stroke="#334155" stroke-width="1.8" />

                <!-- Carrosserie profilée réaliste -->
                <path
                    d="M 96 66
                       C 74 68, 62 92, 62 140
                       C 62 188, 74 212, 96 214
                       L 132 222 L 468 222 L 502 214
                       C 524 210, 534 186, 534 140
                       C 534 94, 524 70, 502 66
                       L 468 58 L 132 58 Z"
                    fill="url(#carBodyGrad)"
                    stroke="#1e293b"
                    stroke-width="2.4"
                    filter="url(#carDropShadow)"
                />

                <!-- Nervures du capot avant -->
                <path d="M 88 94 Q 140 88, 196 86" fill="none" stroke="#cbd5e1" stroke-width="1.6" />
                <path d="M 88 186 Q 140 192, 196 194" fill="none" stroke="#cbd5e1" stroke-width="1.6" />
                <line x1="78" y1="140" x2="188" y2="140" stroke="#e2e8f0" stroke-width="1.2" stroke-dasharray="4 4" />

                <!-- Phares avant LED -->
                <path d="M 74 82 Q 86 74, 102 76 Q 92 92, 74 98 Z" fill="#fef08a" stroke="#ca8a04" stroke-width="1.3" />
                <path d="M 74 198 Q 86 206, 102 204 Q 92 188, 74 182 Z" fill="#fef08a" stroke="#ca8a04" stroke-width="1.3" />

                <!-- Feux arrière -->
                <path d="M 522 80 Q 510 74, 498 78 Q 506 94, 522 98 Z" fill="#fca5a5" stroke="#b91c1c" stroke-width="1.3" />
                <path d="M 522 200 Q 510 206, 498 202 Q 506 186, 522 182 Z" fill="#fca5a5" stroke="#b91c1c" stroke-width="1.3" />

                <!-- Pare-brise avant incurvé -->
                <path
                    d="M 190 74 C 204 68, 232 68, 250 76 L 246 204 C 232 212, 204 212, 190 206 C 180 176, 180 104, 190 74 Z"
                    fill="url(#carGlassGrad)"
                    stroke="#475569"
                    stroke-width="1.8"
                />

                <!-- Lunette arrière incurvée -->
                <path
                    d="M 412 78 C 430 72, 448 74, 460 82 C 468 108, 468 172, 460 198 C 448 206, 430 208, 412 202 Z"
                    fill="url(#carGlassGrad)"
                    stroke="#475569"
                    stroke-width="1.8"
                />

                <!-- Vitres latérales gauche & droite -->
                <path d="M 252 68 L 408 72 L 404 82 L 254 80 Z" fill="#bfdbfe" stroke="#64748b" stroke-width="1.2" />
                <path d="M 252 212 L 408 208 L 404 198 L 254 200 Z" fill="#bfdbfe" stroke="#64748b" stroke-width="1.2" />

                <!-- Toit sculpté -->
                <rect x="248" y="80" width="162" height="120" rx="14" fill="url(#carRoofGrad)" stroke="#475569" stroke-width="1.8" />
                <!-- Lignes de portières & poignées -->
                <line x1="326" y1="58" x2="326" y2="80" stroke="#94a3b8" stroke-width="1.4" />
                <line x1="326" y1="200" x2="326" y2="222" stroke="#94a3b8" stroke-width="1.4" />
                <rect x="284" y="61" width="14" height="3" rx="1.5" fill="#64748b" />
                <rect x="358" y="61" width="14" height="3" rx="1.5" fill="#64748b" />
                <rect x="284" y="216" width="14" height="3" rx="1.5" fill="#64748b" />
                <rect x="358" y="216" width="14" height="3" rx="1.5" fill="#64748b" />

                <!-- Coffre arrière -->
                <line x1="484" y1="84" x2="484" y2="196" stroke="#cbd5e1" stroke-width="1.5" />

                <!-- Marqueurs de dégâts numérotés -->
                <g
                    v-for="(m, idx) in modelValue"
                    :key="idx"
                    :transform="`translate(${(m.x / 100) * 600}, ${(m.y / 100) * 280})`"
                    style="cursor: pointer;"
                    @click.stop="removeMarker(idx)"
                >
                    <circle r="15" :fill="metaFor(m.type).color" opacity="0.22" />
                    <circle r="11" :fill="metaFor(m.type).color" stroke="#ffffff" stroke-width="2.5" />
                    <text y="4" text-anchor="middle" font-size="11" fill="#ffffff" font-weight="800">{{ idx + 1 }}</text>
                </g>
            </svg>
        </div>

        <!-- Liste détaillée des défauts relevés -->
        <div v-if="modelValue?.length" class="mt-3 pt-3 border-top">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small fw-bold text-dark">
                    {{ modelValue.length }} défaut(s) relevé(s) sur la carrosserie
                </span>
                <button v-if="!readonly" type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0" @click="clearAll">
                    Tout effacer
                </button>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span
                    v-for="(m, idx) in modelValue"
                    :key="idx"
                    class="badge rounded-pill px-3 py-2 d-inline-flex align-items-center gap-2 fw-semibold"
                    :style="{ background: metaFor(m.type).bg, color: metaFor(m.type).text, border: `1px solid ${metaFor(m.type).color}40` }"
                >
                    <span class="rounded-circle text-white d-inline-flex align-items-center justify-content-center" :style="{ width: '18px', height: '18px', background: metaFor(m.type).color, fontSize: '0.68rem' }">
                        {{ idx + 1 }}
                    </span>
                    <span>{{ metaFor(m.type).label }}</span>
                    <span v-if="m.zone" class="opacity-75 fw-normal">• {{ m.zone }}</span>
                    <button
                        v-if="!readonly"
                        type="button"
                        class="btn-close ms-1"
                        style="font-size: 0.55rem;"
                        aria-label="Retirer ce marqueur"
                        @click="removeMarker(idx)"
                    ></button>
                </span>
            </div>
        </div>
        <div v-else class="text-center text-muted small mt-2">
            Aucun défaut marqué — véhicule déclaré sans dommage apparent.
        </div>
    </div>
</template>
