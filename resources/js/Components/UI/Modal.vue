<!--
  Components/UI/Modal.vue

  A reusable, animated modal component with a "Liquid Glass" aesthetic overlay.
  It handles visibility transitions, page scroll locking and outside-click
  closing.

  Escape is not ours to implement: this renders a native <dialog> opened with
  showModal(), so the browser answers the close request itself and tells us
  through the dialog's own close event. A document-level keydown listener per
  mounted modal, which is what used to be here, only duplicated it.
-->
<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { createScrollLockToken, lockPageScroll, releasePageScroll } from '@/composables/useScrollLock'

/**
 * Component Props
 *
 * @property {Boolean} show - Whether the modal is currently visible.
 * @property {String} maxWidth - Controls the maximum width of the modal (sm, md, lg, xl, 2xl).
 * @property {Boolean} closeable - Determines if the modal can be closed via background click or Escape key.
 * @property {String} ariaLabelledby - The ID of the element providing the modal's accessible title.
 * @property {String} position - Where the panel sits on small screens: 'center' (default) or 'bottom' for a thumb-reachable sheet.
 */
const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    position: {
        type: String,
        default: 'center',
        validator: (value) => ['center', 'bottom'].includes(value),
    },
    closeable: {
        type: Boolean,
        default: true,
    },
    ariaLabelledby: {
        type: String,
        default: null,
    },
})

/**
 * Component Emits
 *
 * @event close - Emitted when the modal requests to close (background click or Escape key).
 */
const emit = defineEmits(['close'])
const dialog = ref()
const showSlot = ref(props.show)

/** This instance's claim on the page scroll. See composables/useScrollLock. */
const scrollLock = createScrollLockToken('modal')

/**
 * The pending close of the <dialog>, held back 200ms so the panel can fade.
 *
 * It has to be cancellable. Re-opening inside that window — confirming one
 * deletion and immediately asking for the next, two taps apart on the workout
 * page — left the stale timer to close the dialog that had just re-opened. The
 * page was locked, and there was no modal on screen to close.
 */
let pendingClose = null

const cancelPendingClose = () => {
    if (pendingClose !== null) {
        clearTimeout(pendingClose)
        pendingClose = null
    }
}

const open = () => {
    cancelPendingClose()
    lockPageScroll(scrollLock)
    showSlot.value = true

    // Opening an already-open dialog throws in some browsers, and the throw
    // would abort this function with the lock already taken.
    if (dialog.value && !dialog.value.open) {
        dialog.value.showModal()
    }
}

const shut = () => {
    releasePageScroll(scrollLock)
    cancelPendingClose()

    pendingClose = setTimeout(() => {
        pendingClose = null
        dialog.value?.close()
        showSlot.value = false
    }, 200)
}

watch(
    () => props.show,
    (isVisible) => (isVisible ? open() : shut()),
)

const close = () => {
    if (props.closeable) {
        emit('close')
    }
}

/**
 * The browser closes a modal <dialog> on its own for a close request — Escape,
 * or whatever the platform maps to it. None of our code runs first, so the
 * dialog would leave the screen while the page stayed locked, and the parent
 * would go on believing its modal was still open.
 */
const onDialogClose = () => {
    releasePageScroll(scrollLock)

    if (props.show) {
        // Report it and let the parent shut us the ordinary way, so the panel
        // is torn down on the same schedule however the close was asked for.
        emit('close')

        return
    }

    showSlot.value = false
}

/**
 * A close request is refusable, and that is the native way to say a modal is
 * not dismissible — rather than letting the dialog go and leaving `show` behind
 * to describe something that is no longer there.
 */
const onDialogCancel = (event) => {
    if (!props.closeable) {
        event.preventDefault()
    }
}

onMounted(() => {
    if (props.show) {
        open()
    }
})

onUnmounted(() => {
    cancelPendingClose()
    releasePageScroll(scrollLock)

    // The dialog may already be detached, which makes close() throw.
    try {
        dialog.value?.close()
    } catch {
        // Nothing to close.
    }
})

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth]
})

/**
 * A bottom sheet keeps the panel under the thumb on a phone, which is how the
 * template editors present their exercise picker. It is a placement choice
 * only — everything a modal has to get right (focus trap, Escape, the inert
 * background) comes from the native <dialog> underneath either way. Above the
 * `sm` breakpoint both variants centre, as before.
 */
const isBottomSheet = computed(() => props.position === 'bottom')

const containerClass = computed(() =>
    isBottomSheet.value ? 'flex min-h-full flex-col justify-end sm:justify-center' : '',
)

const panelSpacingClass = computed(() => (isBottomSheet.value ? '' : 'mb-6'))
</script>

<template>
    <Teleport to="body">
        <dialog
            class="z-[100] m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
            ref="dialog"
            :aria-labelledby="ariaLabelledby"
            @close="onDialogClose"
            @cancel="onDialogCancel"
        >
            <div class="fixed inset-0 z-[100] overflow-y-auto px-4 py-6 sm:px-0" :class="containerClass" scroll-region>
                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div v-show="show" class="fixed inset-0 transform transition-all" @click="close">
                        <div class="glass-overlay" />
                    </div>
                </Transition>

                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <!--
                      overflow-y-auto, not overflow-hidden: the panel is capped
                      at 85vh by glass-modal, and `hidden` clipped everything
                      past that cap with no way to reach it — the bottom of a
                      long exercise list simply did not exist. `auto` clips the
                      same rounded corners and scrolls instead of swallowing.
                    -->
                    <div
                        v-show="show"
                        class="glass-modal text-text-main transform overflow-y-auto rounded-3xl border border-white/20 bg-white/80 shadow-2xl backdrop-blur-xl transition-all sm:mx-auto sm:w-full dark:bg-slate-900/80 dark:text-white"
                        :class="[maxWidthClass, panelSpacingClass]"
                    >
                        <slot v-if="showSlot" />
                    </div>
                </Transition>
            </div>
        </dialog>
    </Teleport>
</template>
