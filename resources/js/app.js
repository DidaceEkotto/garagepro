import $ from 'jquery';
window.$ = window.jQuery = $;

import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { initPageLoadingListener } from './composables/usePageLoading';

initPageLoadingListener();

// Chargement à la demande : jQuery UI uniquement si un .admin-datepicker existe
// (DatePickerInput.vue le charge déjà paresseusement quand il est monté)
if (document.querySelector('.admin-datepicker')) {
    import('jquery-ui-dist/jquery-ui.min.js').catch(() => {});
}

// Chargement à la demande de Select2 uniquement quand la page contient au moins un <select>
let select2Promise = null;
let select2DebounceTimer = null;

function hasEligibleSelect() {
    return document.querySelector('select:not(.no-select2):not(.ui-datepicker-month):not(.ui-datepicker-year)') !== null;
}

function scheduleSelect2Init() {
    if (select2DebounceTimer) clearTimeout(select2DebounceTimer);
    select2DebounceTimer = setTimeout(() => {
        if (!hasEligibleSelect()) return;
        if (!select2Promise) {
            select2Promise = import('select2/dist/js/select2.full.min.js').catch(() => {
                select2Promise = null;
            });
        }
        select2Promise.then(() => initSelect2());
    }, 100);
}

scheduleSelect2Init();
router.on('navigate', scheduleSelect2Init);

const observer = new MutationObserver((mutations) => {
    for (const m of mutations) {
        if (m.addedNodes.length > 0) {
            scheduleSelect2Init();
            break;
        }
    }
});
observer.observe(document.body, { childList: true, subtree: true });

function initSelect2() {
    if (!window.$ || !window.$.fn || !window.$.fn.select2) return;

    document.querySelectorAll('select:not(.no-select2):not(.ui-datepicker-month):not(.ui-datepicker-year)').forEach((el) => {
        if (el.closest('.ui-datepicker')) {
            return;
        }
        const $el = window.$(el);
        if ($el.data('select2')) {
            return;
        }

        const $modal = $el.closest('.modal');
        const isInInputGroup = $el.closest('.input-group').length > 0;
        const options = {
            theme: 'bootstrap-5',
            width: isInInputGroup ? 'resolve' : '100%',
        };
        if ($modal.length) {
            options.dropdownParent = $modal;
        }

        $el.select2(options);

        $el.on('select2:select select2:unselect change.s2bridge', function () {
            if (el.dataset.s2Syncing === '1') return;
            el.dataset.s2Syncing = '1';
            el.dispatchEvent(new Event('change', { bubbles: true }));
            setTimeout(() => {
                delete el.dataset.s2Syncing;
            }, 0);
        });
    });
}

createInertiaApp({
    title: (title) => title ? `${title} - GaragePro` : 'GaragePro',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        const loader = pages[`./Pages/${name}.vue`];
        if (!loader) {
            throw new Error(`Page not found: ${name}`);
        }
        return loader();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
