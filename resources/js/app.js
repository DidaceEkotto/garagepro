import $ from 'jquery';
window.$ = window.jQuery = $;

import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';

// Charge jQuery UI et Select2 après exposition de window.jQuery (évite le hoisting ESM)
import('jquery-ui-dist/jquery-ui.min.js').catch(() => {});
import('select2/dist/js/select2.full.min.js')
    .then(() => {
        initSelect2();
        router.on('navigate', () => setTimeout(initSelect2, 60));

        let scheduled = false;
        const observer = new MutationObserver(() => {
            if (scheduled) return;
            scheduled = true;
            requestAnimationFrame(() => {
                scheduled = false;
                initSelect2();
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    })
    .catch(() => {});

function initSelect2() {
    if (!window.$ || !window.$.fn || !window.$.fn.select2) return;

    document.querySelectorAll('select:not(.no-select2)').forEach((el) => {
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
