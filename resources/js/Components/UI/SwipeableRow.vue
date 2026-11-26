<script setup>
/**
 * Une rangée qu'on tire au doigt pour découvrir une action : `action-left` à
 * droite du doigt, `action-right` à gauche. La position vit hors de Vue, écrite
 * une fois par image sur l'élément ; seul `data-ouverte` est réactif. La
 * mécanique est dans `Utils/glissement.js`.
 */
import { ref, computed, watch, useSlots, onMounted, onBeforeUnmount } from 'vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { elastique, vitesseDuGeste, cibleAuRelachement, pasDuRessort } from '@/Utils/glissement'

const slots = useSlots()

const props = defineProps({
    disabled: { type: Boolean, default: false },
    /** Largeur de la rangée ouverte, en pixels : celle où elle se pose. */
    actionThreshold: { type: Number, default: 80 },
    /** Le rayon du panneau d'action : celui de la carte qu'il découvre. */
    rayon: { type: String, default: 'rounded-carte' },
})

const ECART = 8
const HYSTERESE = 10
const REPONSE = 0.34
const AMORTISSEMENT = 0.86

/** `null`, `'gauche'` (l'action de gauche est découverte) ou `'droite'`. */
const ouverte = ref(null)

const racine = ref(null)
const contenu = ref(null)
const couche = ref(null)
const panneauGauche = ref(null)
const panneauDroit = ref(null)

const aActionGauche = computed(() => Boolean(slots['action-left']))
const aActionDroite = computed(() => Boolean(slots['action-right']))

let x = 0
let v = 0
let cible = 0
let reglage = { reponse: REPONSE, amortissement: 1 }
let image = 0
let derniereImage = null
let saisie = null
let aAvaler = false
let avalerJusqua = Infinity

const crans = () =>
    [aActionDroite.value ? -props.actionThreshold : null, 0, aActionGauche.value ? props.actionThreshold : null].filter(
        (cran) => cran !== null,
    )

const mouvementReduit = () => Boolean(globalThis.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches)

function ecrirePanneau(element, largeur) {
    if (!element) {
        return
    }

    element.style.width = `${largeur}px`
    element.style.setProperty('--fondu', String(Math.min(1, Math.max(0, (largeur - 24) / 32))))
}

function ecrire() {
    if (!contenu.value) {
        return
    }

    contenu.value.style.transform = `translateX(${x}px)`
    ecrirePanneau(panneauGauche.value, Math.max(0, x - ECART))
    ecrirePanneau(panneauDroit.value, Math.max(0, -x - ECART))
}

function arreter() {
    cancelAnimationFrame(image)
    image = 0
}

function pas(maintenant) {
    const dt = derniereImage === null ? 1 / 60 : Math.min(0.032, Math.max(0, (maintenant - derniereImage) / 1000))

    derniereImage = maintenant
    ;({ x, v } = pasDuRessort({ x, v }, cible, dt, reglage))

    if (Math.abs(x - cible) < 0.3 && Math.abs(v) < 4) {
        x = cible
        v = 0
        image = 0
        ecrire()

        return
    }

    ecrire()
    image = requestAnimationFrame(pas)
}

function aller(arrivee, { vitesse = 0, reponse = REPONSE, amortissement = 1 } = {}) {
    arreter()
    cible = arrivee
    v = vitesse
    reglage = { reponse, amortissement }

    if (mouvementReduit() || (x === arrivee && vitesse === 0)) {
        x = arrivee
        v = 0
        ecrire()

        return
    }

    derniereImage = null
    image = requestAnimationFrame(pas)
}

function suivre(souhaitee) {
    const seuil = props.actionThreshold
    const plafond = aActionGauche.value ? seuil : 0
    const plancher = aActionDroite.value ? -seuil : 0

    if (souhaitee > plafond) {
        return plafond === 0 ? 0 : plafond + elastique(souhaitee - plafond, 2 * seuil)
    }

    if (souhaitee < plancher) {
        return plancher === 0 ? 0 : plancher - elastique(plancher - souhaitee, 2 * seuil)
    }

    return souhaitee
}

const estUnDoigt = (evenement) => evenement.pointerType === 'touch' || evenement.pointerType === 'pen'

function relacherLaCapture() {
    if (saisie?.verrou !== 'horizontal') {
        return
    }

    try {
        contenu.value?.releasePointerCapture?.(saisie.id)
    } catch {
        // Le navigateur l'a déjà rendue avec le pointeur.
    }
}

function auPointeurPose(evenement) {
    if (saisie && estUnDoigt(evenement)) {
        return
    }

    aAvaler = false

    if (props.disabled || !estUnDoigt(evenement)) {
        return
    }

    arreter()
    v = 0
    avalerJusqua = Infinity
    aAvaler = ouverte.value === null ? false : 'fermeture'

    const depart =
        ouverte.value === 'gauche' ? props.actionThreshold : ouverte.value === 'droite' ? -props.actionThreshold : 0

    saisie = {
        id: evenement.pointerId,
        x0: evenement.clientX,
        y0: evenement.clientY,
        verrou: null,
        origine: 0,
        xDeDepart: x,
        cranDeDepart: crans().includes(depart) ? depart : 0,
        echantillons: [],
    }
}

function auPointeurBouge(evenement) {
    if (!saisie || evenement.pointerId !== saisie.id || props.disabled || saisie.verrou === 'vertical') {
        return
    }

    if (saisie.verrou === null) {
        const dx = evenement.clientX - saisie.x0
        const dy = evenement.clientY - saisie.y0

        if (Math.abs(dx) <= HYSTERESE && Math.abs(dy) <= HYSTERESE) {
            return
        }

        if (Math.abs(dx) <= Math.abs(dy)) {
            saisie.verrou = 'vertical'

            return
        }

        saisie.verrou = 'horizontal'
        saisie.origine = evenement.clientX
        saisie.xDeDepart = x
        aAvaler = 'glissement'

        try {
            contenu.value?.setPointerCapture?.(evenement.pointerId)
        } catch {
            // Un pointeur que le navigateur ne connaît pas (évènement synthétique) : le geste suit quand même.
        }
    }

    x = suivre(saisie.xDeDepart + (evenement.clientX - saisie.origine))
    ecrire()

    saisie.echantillons.push({ t: evenement.timeStamp, x: evenement.clientX })

    while (saisie.echantillons.length > 1 && evenement.timeStamp - saisie.echantillons[0].t > 100) {
        saisie.echantillons.shift()
    }
}

function auPointeurLeve(evenement) {
    if (!saisie || evenement.pointerId !== saisie.id) {
        return
    }

    const { verrou, echantillons, cranDeDepart } = saisie

    relacherLaCapture()
    saisie = null
    avalerJusqua = performance.now() + 500

    if (verrou !== 'horizontal') {
        const repos =
            ouverte.value === 'gauche' ? props.actionThreshold : ouverte.value === 'droite' ? -props.actionThreshold : 0

        if (x !== repos) {
            aller(repos)
        }

        return
    }

    const vitesse = vitesseDuGeste(echantillons, evenement.timeStamp)
    const arrivee = cibleAuRelachement({ x, vitesse, crans: crans(), cranDeDepart })
    const cote = arrivee === 0 ? null : arrivee > 0 ? 'gauche' : 'droite'

    if (cote !== null && cote !== ouverte.value) {
        triggerHaptic('selection')
    }

    ouverte.value = cote
    aller(arrivee, { vitesse, reponse: REPONSE, amortissement: AMORTISSEMENT })
}

function auPointeurAnnule(evenement) {
    if (!saisie || evenement.pointerId !== saisie.id) {
        return
    }

    relacherLaCapture()
    saisie = null
    fermer()
}

function auClicEnCapture(evenement) {
    if (!aAvaler || performance.now() > avalerJusqua) {
        aAvaler = false

        return
    }

    const apres = aAvaler

    aAvaler = false
    evenement.preventDefault()
    evenement.stopPropagation()

    if (apres === 'fermeture') {
        fermer()
    }
}

function fermer() {
    ouverte.value = null
    aller(0)
}

function ouvrirSur(cote) {
    ouverte.value = cote
    aller(cote === 'gauche' ? props.actionThreshold : -props.actionThreshold)
}

function auFocusDUneAction(evenement) {
    if (panneauGauche.value?.contains(evenement.target)) {
        ouvrirSur('gauche')
    } else if (panneauDroit.value?.contains(evenement.target)) {
        ouvrirSur('droite')
    }
}

function auBlurDUneAction(evenement) {
    if (!couche.value?.contains(evenement.relatedTarget)) {
        fermer()
    }
}

function fermerSiDehors(evenement) {
    if (!racine.value?.contains(evenement.target)) {
        fermer()
    }
}

watch(
    () => props.disabled,
    (coupee) => {
        if (!coupee) {
            return
        }

        relacherLaCapture()
        saisie = null
        arreter()
        ouverte.value = null
        x = 0
        v = 0
        ecrire()
    },
)

watch(ouverte, (valeur, avant) => {
    if (valeur !== null && avant === null) {
        document.addEventListener('pointerdown', fermerSiDehors, true)
    } else if (valeur === null && avant !== null) {
        document.removeEventListener('pointerdown', fermerSiDehors, true)
    }
})

onMounted(ecrire)

onBeforeUnmount(() => {
    arreter()
    document.removeEventListener('pointerdown', fermerSiDehors, true)
})

defineExpose({ close: fermer })
</script>

<template>
    <div ref="racine" class="relative overflow-x-clip" :data-ouverte="ouverte ?? undefined">
        <!-- Retirée quand la rangée est coupée : le geste qui la découvre l'est aussi, et ses boutons resteraient invisibles dans l'ordre de tabulation. -->
        <div
            v-if="!disabled"
            ref="couche"
            class="absolute inset-0"
            @focusin="auFocusDUneAction"
            @focusout="auBlurDUneAction"
        >
            <div
                v-if="aActionGauche"
                ref="panneauGauche"
                data-panneau="gauche"
                class="absolute inset-y-0 left-0 flex w-0 overflow-hidden [&_button>*]:opacity-(--fondu)"
                :class="rayon"
            >
                <div
                    class="flex h-full w-full flex-none items-stretch whitespace-nowrap"
                    :style="{ minWidth: `${actionThreshold - ECART}px` }"
                >
                    <slot name="action-left" />
                </div>
            </div>

            <div
                v-if="aActionDroite"
                ref="panneauDroit"
                data-panneau="droite"
                class="absolute inset-y-0 right-0 flex w-0 justify-end overflow-hidden [&_button>*]:opacity-(--fondu)"
                :class="rayon"
            >
                <div
                    class="flex h-full w-full flex-none items-stretch whitespace-nowrap"
                    :style="{ minWidth: `${actionThreshold - ECART}px` }"
                >
                    <slot name="action-right" />
                </div>
            </div>
        </div>

        <div
            ref="contenu"
            data-glissant
            class="relative z-10 touch-pan-y"
            @pointerdown.passive="auPointeurPose"
            @pointermove.passive="auPointeurBouge"
            @pointerup.passive="auPointeurLeve"
            @pointercancel.passive="auPointeurAnnule"
            @click.capture="auClicEnCapture"
        >
            <slot />
        </div>
    </div>
</template>
