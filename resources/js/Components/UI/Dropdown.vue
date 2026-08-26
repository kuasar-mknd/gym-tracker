<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const props = defineProps({
    align: {
        type: String,
        default: 'right',
    },
    width: {
        type: String,
        default: '48',
    },
    /*
     * `bg-surface-card/10` par defaut jusqu'ici : le panneau etait blanc a 10 %,
     * donc illisible sur le fond clair de l'application (#1314).
     * `glass-panel-strong` porte le jeton prevu pour une surface flottante,
     */
    contentClasses: {
        type: String,
        default: 'py-1 glass-panel-strong',
    },
})

const closeOnEscape = (e) => {
    if (open.value && e.key === 'Escape') {
        open.value = false
    }
}

onMounted(() => document.addEventListener('keydown', closeOnEscape))
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape))

const widthClass = computed(() => {
    return {
        48: 'w-48',
    }[props.width.toString()]
})

const alignmentClasses = computed(() => {
    if (props.align === 'left') {
        return 'ltr:origin-top-left rtl:origin-top-right start-0'
    } else if (props.align === 'right') {
        return 'ltr:origin-top-right rtl:origin-top-left end-0'
    } else {
        return 'origin-top'
    }
})

const open = ref(false)
</script>

<template>
    <div class="relative">
        <div @click="open = !open">
            <slot name="trigger" :open="open" />
        </div>

        <!-- Full Screen Dropdown Overlay -->
        <div v-show="open" class="fixed inset-0 z-40" @click="open = false"></div>

        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-show="open"
                class="absolute z-50 mt-2 rounded-2xl"
                :class="[widthClass, alignmentClasses]"
                @click="open = false"
            >
                <!--
                    Ni `style="display: none"` ni `shadow-lg` ici : `v-show`
                    pilote deja l'affichage, et l'ombre vient de
                    `glass-panel-strong`, avec la bordure qui suit le theme.
                    La bordure `border-glass-border/20` codee en dur disparaissait
                    en mode sombre.
                -->
                <div class="rounded-2xl" :class="contentClasses">
                    <slot name="content" />
                </div>
            </div>
        </Transition>
    </div>
</template>
