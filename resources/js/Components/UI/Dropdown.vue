<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

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

const open = ref(false)
const declencheur = ref(null)
const contenu = ref(null)

/** Les entrées du menu, dans l'ordre où le clavier les parcourt. */
const entrees = () => [...(contenu.value?.querySelectorAll('a[href], button:not([disabled])') ?? [])]

/** Le premier contrôle du déclencheur : c'est lui qui reprend le focus à la fermeture au clavier. */
const controleDuDeclencheur = () => declencheur.value?.querySelector('button, a[href], [tabindex]')

const closeOnEscape = (e) => {
    if (open.value && e.key === 'Escape') {
        open.value = false
        controleDuDeclencheur()?.focus()
    }
}

/**
 * Un menu qui s'ouvre sans prendre le focus laisse un clavier sur le
 * déclencheur, derrière un panneau qu'il ne peut pas atteindre sans traverser
 * le reste de la page (#2355). Ouvert, le focus va à la première entrée, et les
 * flèches, Début et Fin parcourent le menu.
 */
watch(open, async (ouvert) => {
    if (!ouvert) {
        return
    }

    await nextTick()
    entrees()[0]?.focus()
})

const parcourir = (e) => {
    const liste = entrees()
    const courant = liste.indexOf(document.activeElement)
    const cible = {
        ArrowDown: liste[(courant + 1) % liste.length],
        ArrowUp: courant < 0 ? liste[liste.length - 1] : liste[(courant - 1 + liste.length) % liste.length],
        Home: liste[0],
        End: liste[liste.length - 1],
    }[e.key]

    if (cible) {
        e.preventDefault()
        cible.focus()
    }
}

/** Flèche bas sur le déclencheur : ouvre le menu, comme un bouton de menu. */
const ouvrirAuClavier = (e) => {
    if (e.key === 'ArrowDown' && !open.value) {
        e.preventDefault()
        open.value = true
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
</script>

<template>
    <div class="relative">
        <div ref="declencheur" @click="open = !open" @keydown="ouvrirAuClavier">
            <slot name="trigger" :open="open" />
        </div>

        <!-- Full Screen Dropdown Overlay -->
        <div v-show="open" class="z-nav fixed inset-0" @click="open = false"></div>

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
                ref="contenu"
                class="z-modale absolute mt-2 rounded-2xl"
                :class="[widthClass, alignmentClasses]"
                @click="open = false"
                @keydown="parcourir"
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
