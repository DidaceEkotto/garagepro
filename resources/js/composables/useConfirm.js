import { reactive } from 'vue';

const confirmState = reactive({
    title: 'Confirmation',
    message: '',
    confirmLabel: 'Confirmer',
    confirmClass: 'btn-danger',
    onConfirm: null,
});

export function useConfirm() {
    function askConfirm({
        title = "Confirmer l'action",
        message,
        confirmLabel = 'Confirmer',
        confirmClass = 'btn-danger',
        onConfirm,
    }) {
        confirmState.title = title;
        confirmState.message = message;
        confirmState.confirmLabel = confirmLabel;
        confirmState.confirmClass = confirmClass;
        confirmState.onConfirm = onConfirm;

        const el = document.getElementById('globalConfirmStaticModal');
        if (el && window.bootstrap?.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(el).show();
        } else if (typeof onConfirm === 'function') {
            onConfirm();
        }
    }

    function executeConfirm() {
        const cb = confirmState.onConfirm;
        confirmState.onConfirm = null;

        const el = document.getElementById('globalConfirmStaticModal');
        if (el && window.bootstrap?.Modal) {
            window.bootstrap.Modal.getInstance(el)?.hide();
        }

        if (typeof cb === 'function') {
            cb();
        }
    }

    return {
        confirmState,
        askConfirm,
        executeConfirm,
    };
}
