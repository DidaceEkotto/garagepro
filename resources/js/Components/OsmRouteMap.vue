<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    targetLat: { type: Number, required: true },
    targetLon: { type: Number, required: true },
    targetLabel: { type: String, default: 'Position de la panne' },
    originLat: { type: Number, default: null },
    originLon: { type: Number, default: null },
    originLabel: { type: String, default: 'Garage dépanneur' },
    extraMarkers: { type: Array, default: () => [] },
    height: { type: String, default: '310px' },
});

const mapEl = ref(null);
const routeInfo = ref(null);
const routing = ref(false);

let map = null;
let layerGroup = null;

function createPin(color, iconClass, pulse = false) {
    return L.divIcon({
        className: 'mboacar-map-pin',
        html: `<div style="position:relative;width:32px;height:32px;display:flex;align-items:center;justify-content:center;">
            ${pulse ? `<span style="position:absolute;inset:-4px;border-radius:999px;background:${color};opacity:0.25;"></span>` : ''}
            <span style="width:28px;height:28px;border-radius:999px;background:${color};color:#fff;border:2.5px solid #fff;box-shadow:0 4px 10px rgba(15,23,42,0.35);display:flex;align-items:center;justify-content:center;font-size:13px;">
                <i class="bi ${iconClass}"></i>
            </span>
        </div>`,
        iconSize: [32, 32],
        iconAnchor: [16, 16],
    });
}

async function renderScene() {
    if (!map || !layerGroup) return;
    layerGroup.clearLayers();
    routeInfo.value = null;

    const bounds = [];

    // 1) Marqueur principal (panne / conducteur)
    const targetPoint = [props.targetLat, props.targetLon];
    bounds.push(targetPoint);
    L.marker(targetPoint, { icon: createPin('#2563eb', 'bi-car-front-fill', true) })
        .bindPopup(`<strong>${props.targetLabel}</strong>`)
        .addTo(layerGroup);

    // 2) Marqueurs secondaires (établissements à proximité)
    for (const m of props.extraMarkers || []) {
        if (m.latitude && m.longitude) {
            const pt = [Number(m.latitude), Number(m.longitude)];
            bounds.push(pt);
            L.marker(pt, { icon: createPin('#0f172a', 'bi-building-fill-gear', false) })
                .bindPopup(`<strong>${m.name}</strong><br/><small>${m.type_label || ''} • ${m.distance_km ?? ''} km</small>`)
                .addTo(layerGroup);
        }
    }

    // 3) Origine (garage dépanneur) + tracé d'itinéraire routier réel OSRM
    if (props.originLat !== null && props.originLon !== null) {
        const originPoint = [Number(props.originLat), Number(props.originLon)];
        bounds.push(originPoint);

        L.marker(originPoint, { icon: createPin('#059669', 'bi-truck', false) })
            .bindPopup(`<strong>${props.originLabel}</strong>`)
            .addTo(layerGroup);

        routing.value = true;
        try {
            const params = new URLSearchParams({
                from_lat: String(props.originLat),
                from_lon: String(props.originLon),
                to_lat: String(props.targetLat),
                to_lon: String(props.targetLon),
            });
            const res = await fetch(`/api/geocode/route?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (data?.ok && Array.isArray(data.coordinates) && data.coordinates.length >= 2) {
                const poly = L.polyline(data.coordinates, {
                    color: '#2563eb',
                    weight: 5,
                    opacity: 0.88,
                    lineJoin: 'round',
                }).addTo(layerGroup);

                routeInfo.value = {
                    distance_km: data.distance_km,
                    duration_min: data.duration_min,
                };
                map.fitBounds(poly.getBounds(), { padding: [36, 36] });
                routing.value = false;
                return;
            }
        } catch {
            // fallback line below
        } finally {
            routing.value = false;
        }

        const fallbackLine = L.polyline([originPoint, targetPoint], {
            color: '#2563eb',
            weight: 4,
            dashArray: '8, 8',
        }).addTo(layerGroup);
        map.fitBounds(fallbackLine.getBounds(), { padding: [36, 36] });
        return;
    }

    if (bounds.length > 1) {
        map.fitBounds(L.latLngBounds(bounds), { padding: [32, 32], maxZoom: 15 });
    } else {
        map.setView(targetPoint, 15);
    }
}

onMounted(() => {
    if (!mapEl.value) return;
    map = L.map(mapEl.value, {
        center: [props.targetLat, props.targetLon],
        zoom: 15,
        scrollWheelZoom: false,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    layerGroup = L.layerGroup().addTo(map);
    renderScene();
});

watch(
    () => [props.targetLat, props.targetLon, props.originLat, props.originLon, props.extraMarkers?.length],
    () => renderScene()
);

onBeforeUnmount(() => {
    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <div>
        <div ref="mapEl" class="w-100" :style="{ height }"></div>
        <div v-if="routeInfo || routing" class="px-3 py-2 bg-primary bg-opacity-10 border-top small d-flex justify-content-between align-items-center">
            <span v-if="routing" class="text-primary">
                <span class="spinner-border spinner-border-sm me-1"></span>Calcul de l'itinéraire routier...
            </span>
            <template v-else-if="routeInfo">
                <span class="fw-semibold text-primary">
                    <i class="bi bi-sign-turn-right-fill me-1"></i>Itinéraire tracé : {{ originLabel }} → {{ targetLabel }}
                </span>
                <span class="badge bg-primary rounded-pill">
                    {{ routeInfo.distance_km }} km • ~{{ routeInfo.duration_min }} min
                </span>
            </template>
        </div>
    </div>
</template>
