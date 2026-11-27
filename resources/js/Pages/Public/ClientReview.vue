<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    review: Object,
});

const form = useForm({
    rating: props.review?.rating ?? 5,
    comment: props.review?.comment ?? '',
    publish_publicly: true,
});

function submit() {
    form.post(`/avis/${props.review.token}`);
}
</script>

<template>
    <Head :title="`Votre avis — ${review.garage_name}`" />
    <div class="min-vh-100 bg-light d-flex align-items-center justify-content-center p-3">
        <div class="card border-0 shadow-sm rounded-4" style="max-width: 520px; width: 100%;">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="badge bg-primary-subtle text-primary mb-2">Satisfaction Client</div>
                    <h1 class="h4 fw-bold mb-1">{{ review.garage_name }}</h1>
                    <p class="text-muted small mb-0">
                        Intervention {{ review.order_number }} • {{ review.vehicle_label }}
                    </p>
                </div>

                <div v-if="review.submitted_at" class="alert alert-success text-center small">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    Merci {{ review.customer_name }} ! Votre avis ({{ review.rating }}/5) a été enregistré le {{ review.submitted_at }}.
                </div>

                <form @submit.prevent="submit">
                    <div class="text-center mb-4">
                        <div class="small text-muted mb-2">Comment évaluez-vous la qualité de notre intervention ?</div>
                        <div class="d-flex justify-content-center gap-2">
                            <button
                                v-for="star in 5"
                                :key="star"
                                type="button"
                                class="btn btn-link p-1 fs-2 text-decoration-none"
                                :class="star <= form.rating ? 'text-warning' : 'text-secondary opacity-25'"
                                @click="form.rating = star"
                            >
                                <i class="bi bi-star-fill"></i>
                            </button>
                        </div>
                        <div class="fw-semibold mt-1 small">{{ form.rating }} / 5 étoiles</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Votre commentaire (facultatif)</label>
                        <textarea
                            v-model="form.comment"
                            rows="3"
                            class="form-control"
                            placeholder="Partagez votre expérience avec notre atelier..."
                        ></textarea>
                    </div>

                    <div v-if="form.rating >= 4" class="alert alert-success border-0 small py-2 mb-4">
                        <div class="form-check mb-0">
                            <input id="pubReview" v-model="form.publish_publicly" type="checkbox" class="form-check-input" />
                            <label class="form-check-label fw-semibold" for="pubReview">
                                Publier mon avis sur la page publique de {{ review.garage_name }}
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" :disabled="form.processing">
                        <i class="bi bi-send-fill me-1"></i>Envoyer mon avis
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
