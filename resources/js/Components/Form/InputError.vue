<script setup>
/**
 * InputError.vue
 *
 * A reusable component for displaying validation error messages associated with
 * form inputs. It ensures accessibility by linking to inputs via `aria-describedby`
 * and applies the "Liquid Glass" visual aesthetic for error states.
 */

/**
 * Component Props
 *
 * @property {String} message - The error message text to display. If empty, the component remains hidden.
 * @property {String} id - The unique ID assigned to the error element. This is required to properly associate the error message with its corresponding input using `aria-describedby` for screen readers.
 */
defineProps({
    message: {
        type: String,
    },
    id: {
        type: String,
    },
})
</script>

<template>
    <div
        v-show="message"
        :id="id"
        role="alert"
        class="animate-fade-in border-accent-danger/20 bg-accent-danger/10 hover:bg-accent-danger/20 mt-2 flex w-full cursor-default items-start gap-2 rounded-2xl border px-4 py-3 backdrop-blur-md transition-all duration-300"
    >
        <span class="material-symbols-outlined text-accent-danger mt-0.5 text-lg" aria-hidden="true">error</span>
        <p class="text-accent-danger text-sm font-bold">
            {{ message }}
        </p>
    </div>
</template>
