import { ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const cartState = ref({ items: [], count: 0, total: 0 });
const toastState = ref({ visible: false, type: 'success', message: '', duplicateProductId: null });
let toastTimer = null;

function getCsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function showToast(message, type = 'success', duplicateProductId = null) {
    toastState.value = { visible: true, type, message, duplicateProductId };
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastState.value.visible = false;
    }, 5500);
}

export function useMarketplaceCart() {
    const page = usePage();

    watch(
        () => page.props.marketplaceCart,
        (val) => {
            if (val) {
                cartState.value = val;
            }
        },
        { immediate: true }
    );

    async function addToCart(productId, quantity = 1, increment = false) {
        try {
            const res = await fetch(`/panier/ajouter/${productId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ quantity, increment }),
            });

            const data = await res.json();
            if (data.cart) {
                cartState.value = data.cart;
            }

            if (res.status === 409 || data.already_in_cart) {
                showToast(data.message, 'warning', productId);
                return { ok: false, alreadyInCart: true, message: data.message };
            }

            if (!res.ok) {
                showToast(data.message || 'Action impossible.', 'danger', null);
                return { ok: false, alreadyInCart: false, message: data.message };
            }

            showToast(data.message || 'Ajouté au panier.', 'success', null);
            router.reload({ preserveScroll: true, preserveState: true });
            return { ok: true, alreadyInCart: false, message: data.message };
        } catch {
            showToast('Erreur réseau lors de l\'ajout au panier.', 'danger', null);
            return { ok: false, alreadyInCart: false };
        }
    }

    async function updateQuantity(productId, quantity) {
        const res = await fetch(`/panier/${productId}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ quantity }),
        });
        const data = await res.json();
        if (data.cart) {
            cartState.value = data.cart;
        }
        if (!res.ok && data.message) {
            showToast(data.message, 'danger', null);
        } else {
            router.reload({ preserveScroll: true, preserveState: true });
        }
    }

    async function removeItem(productId) {
        const res = await fetch(`/panier/${productId}`, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
        });
        const data = await res.json();
        if (data.cart) {
            cartState.value = data.cart;
        }
        showToast(data.message || 'Article retiré du panier et stock restauré.', 'info', null);
        router.reload({ preserveScroll: true, preserveState: true });
    }

    return {
        cart: cartState,
        toast: toastState,
        addToCart,
        updateQuantity,
        removeItem,
        hideToast: () => (toastState.value.visible = false),
    };
}
