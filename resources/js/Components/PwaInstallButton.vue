<script setup>
import { usePwaInstall } from '@/composables/usePwaInstall';
import { computed, ref } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'emerald' }, // emerald | light | primary | dark
    size: { type: String, default: 'sm' }, // sm | md | lg
    label: { type: String, default: "Télécharger l'app" },
    compact: { type: Boolean, default: false },
    fullWidth: { type: Boolean, default: false },
});

const { showInstallButton, isIos, triggerInstall } = usePwaInstall();
const modalId = `modalPwaInstallGuide-${Math.random().toString(36).slice(2, 8)}`;
const copiedLink = ref(false);

const ua = typeof navigator !== 'undefined' ? navigator.userAgent : '';
const isFirefoxDesktop = /firefox/i.test(ua) && !/android|mobile|tablet/i.test(ua);
const isFirefoxAndroid = /firefox/i.test(ua) && /android/i.test(ua);

async function copyAppUrl() {
    try {
        await navigator.clipboard.writeText(window.location.origin);
        copiedLink.value = true;
        setTimeout(() => {
            copiedLink.value = false;
        }, 2500);
    } catch {
        // ignore clipboard error
    }
}

const btnClass = computed(() => {
    const classes = ['btn', 'd-inline-flex', 'align-items-center', 'justify-content-center', 'gap-2', 'fw-semibold', 'text-nowrap'];
    if (props.size === 'sm') classes.push('btn-sm', 'rounded-pill', 'px-3');
    else if (props.size === 'lg') classes.push('btn-lg', 'rounded-pill', 'px-4');
    else classes.push('rounded-pill', 'px-3');

    if (props.fullWidth) classes.push('w-100');

    if (props.variant === 'light') classes.push('btn-outline-light');
    else if (props.variant === 'outline-primary') classes.push('btn-outline-primary');
    else if (props.variant === 'primary') classes.push('btn-primary');
    else if (props.variant === 'dark') classes.push('btn-dark');
    else classes.push('btn-success');

    return classes.join(' ');
});

async function handleClick() {
    const result = await triggerInstall();
    if (result === 'manual') {
        const el = document.getElementById(modalId);
        if (el && window.bootstrap?.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(el).show();
        }
    }
}
</script>

<template>
    <button
        v-if="showInstallButton"
        type="button"
        :class="btnClass"
        title="Installer MboaCar comme application (PC, Android, iPhone)"
        @click="handleClick"
    >
        <i class="bi bi-download"></i>
        <span :class="compact ? 'd-none d-md-inline' : ''">{{ label }}</span>
    </button>

    <!-- Modal guide d'installation (iPhone/iPad Safari ou navigateur sans invite native) -->
    <Teleport to="body">
        <div class="modal fade" :id="modalId" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4">
                    <div class="modal-header border-bottom">
                        <h5 class="modal-title fw-bold d-flex align-items-center gap-2 mb-0">
                            <i class="bi bi-phone-fill text-primary"></i>
                            Installer l'application MboaCar
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body p-4">
                        <p class="small text-muted mb-3">
                            Installez MboaCar directement sur votre écran d'accueil ou bureau pour un accès rapide et le <strong>Mode Hors Ligne</strong> :
                        </p>

                        <div v-if="isIos" class="bg-light rounded-3 p-3 mb-3">
                            <div class="fw-bold small text-dark mb-2">
                                <i class="bi bi-apple me-1"></i>Sur iPhone / iPad (Safari)
                            </div>
                            <ol class="small mb-0 ps-3 vstack gap-1">
                                <li>Appuyez sur le bouton <strong>Partager</strong> <i class="bi bi-box-arrow-up text-primary"></i> en bas de Safari.</li>
                                <li>Faites défiler et appuyez sur <strong>« Sur l'écran d'accueil »</strong>.</li>
                                <li>Appuyez sur <strong>Ajouter</strong> en haut à droite.</li>
                            </ol>
                        </div>

                        <div v-else-if="isFirefoxDesktop" class="bg-light rounded-3 p-3 mb-3">
                            <div class="fw-bold small text-dark mb-2">
                                <i class="bi bi-browser-firefox me-1 text-warning"></i>Vous utilisez Firefox sur ordinateur
                            </div>
                            <p class="small text-muted mb-2">
                                Firefox sur PC ne propose plus nativement l'installation d'applications bureau.
                                Bonne nouvelle : le <strong>Mode Hors Ligne (14 jours)</strong> fonctionne déjà parfaitement ici !
                            </p>
                            <ol class="small mb-3 ps-3 vstack gap-1">
                                <li><strong>Recommandé :</strong> ouvrez MboaCar dans <strong>Edge</strong>, <strong>Chrome</strong> ou <strong>Brave</strong> puis cliquez sur <strong>« Télécharger l'app »</strong> pour obtenir l'icône sur votre bureau.</li>
                                <li><strong>Pour rester sur Firefox :</strong> installez l'extension officielle gratuite <em>« PWAs for Firefox »</em> ou épinglez cet onglet.</li>
                            </ol>
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill" @click="copyAppUrl">
                                <i class="bi me-1" :class="copiedLink ? 'bi-check2 text-success' : 'bi-clipboard'"></i>
                                {{ copiedLink ? 'Lien copié ! Collez-le dans Chrome / Edge' : 'Copier le lien MboaCar pour Chrome / Edge' }}
                            </button>
                        </div>

                        <div v-else-if="isFirefoxAndroid" class="bg-light rounded-3 p-3 mb-3">
                            <div class="fw-bold small text-dark mb-2">
                                <i class="bi bi-browser-firefox me-1 text-warning"></i>Sur Android (Firefox)
                            </div>
                            <ol class="small mb-0 ps-3 vstack gap-1">
                                <li>Appuyez sur le menu <strong>⋮</strong> en haut ou en bas à droite de Firefox.</li>
                                <li>Appuyez sur <strong>« Installer »</strong> ou <strong>« Ajouter à l'écran d'accueil »</strong>.</li>
                                <li>Confirmez : l'icône MboaCar apparaît sur votre téléphone !</li>
                            </ol>
                        </div>

                        <div v-else class="bg-light rounded-3 p-3 mb-3">
                            <div class="fw-bold small text-dark mb-2">
                                <i class="bi bi-laptop me-1 text-primary"></i>Sur PC Windows / Android (Chrome, Edge, Brave)
                            </div>
                            <ol class="small mb-0 ps-3 vstack gap-1">
                                <li>Cliquez sur l'icône <strong>Installer</strong> <i class="bi bi-plus-square text-primary"></i> à droite dans la barre d'adresse (ou le menu <strong>⋮</strong> en haut à droite).</li>
                                <li>Sélectionnez <strong>« Installer MboaCar »</strong> ou <strong>« Ajouter à l'écran d'accueil »</strong>.</li>
                                <li>Validez : l'icône MboaCar apparaît immédiatement sur votre appareil !</li>
                            </ol>
                        </div>

                        <div class="small text-muted">
                            <i class="bi bi-shield-check text-success me-1"></i>
                            Aucune boutique requise, mise à jour automatique et stockage ultra-léger.
                        </div>
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-primary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Compris</button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
