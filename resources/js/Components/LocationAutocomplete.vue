<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    country: { type: String, default: '' },
    placeholder: { type: String, default: 'Tapez votre quartier, rue ou ville (OpenStreetMap)...' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'select']);

const query = ref(props.modelValue || '');
const results = ref([]);
const loading = ref(false);
const open = ref(false);
const locating = ref(false);
let debounce = null;

watch(
    () => props.modelValue,
    (v) => {
        if (v !== query.value) query.value = v || '';
    }
);

function onInput(e) {
    const val = e.target.value;
    query.value = val;
    emit('update:modelValue', val);

    if (debounce) clearTimeout(debounce);
    if (val.trim().length < 2) {
        results.value = [];
        open.value = false;
        return;
    }

    debounce = setTimeout(async () => {
        loading.value = true;
        try {
            const params = new URLSearchParams({ q: val.trim() });
            if (props.country) params.set('country', props.country);
            const res = await fetch(`/api/geocode/search?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            results.value = data.results || [];
            open.value = results.value.length > 0;
        } catch {
            results.value = [];
        } finally {
            loading.value = false;
        }
    }, 320);
}

function choose(item) {
    const label = item.short_label || item.city || item.display_name;
    query.value = label;
    open.value = false;
    results.value = [];
    emit('update:modelValue', label);
    emit('select', {
        city: item.city || label,
        formatted_location: item.display_name,
        latitude: item.lat,
        longitude: item.lon,
    });
}

function useGps() {
    if (!navigator.geolocation || locating.value) return;
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        async (pos) => {
            try {
                const lat = pos.coords.latitude;
                const lon = pos.coords.longitude;
                const res = await fetch(`/api/geocode/reverse?lat=${lat}&lon=${lon}`, {
                    headers: { Accept: 'application/json' },
                });
                const data = await res.json();
                const r = data.result;
                const label = r?.short_label || r?.city || `${lat.toFixed(4)}, ${lon.toFixed(4)}`;
                query.value = label;
                emit('update:modelValue', label);
                emit('select', {
                    city: r?.city || label,
                    formatted_location: r?.display_name || label,
                    latitude: lat,
                    longitude: lon,
                });
            } finally {
                locating.value = false;
            }
        },
        () => {
            locating.value = false;
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}
</script>

<template>
    <div class="position-relative">
        <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-geo-alt-fill text-primary"></i></span>
            <input
                :value="query"
                type="text"
                class="form-control"
                :placeholder="placeholder"
                :required="required"
                autocomplete="off"
                @input="onInput"
                @focus="results.length && (open = true)"
            />
            <button
                type="button"
                class="btn btn-outline-primary"
                title="Détecter ma position GPS (OpenStreetMap)"
                :disabled="locating"
                @click="useGps"
            >
                <span v-if="locating || loading" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-crosshair"></i>
            </button>
        </div>

        <div
            v-if="open && results.length"
            class="list-group position-absolute w-100 shadow-lg mt-1"
            style="z-index: 1060; max-height: 230px; overflow-y: auto;"
        >
            <button
                v-for="(item, idx) in results"
                :key="idx"
                type="button"
                class="list-group-item list-group-item-action py-2 px-3 text-start small"
                @click="choose(item)"
            >
                <div class="fw-semibold text-dark">
                    <i class="bi bi-pin-map-fill text-primary me-1"></i>{{ item.short_label || item.city }}
                </div>
                <div class="text-muted text-truncate" style="font-size: 0.72rem;">{{ item.display_name }}</div>
            </button>
        </div>
    </div>
</template>
