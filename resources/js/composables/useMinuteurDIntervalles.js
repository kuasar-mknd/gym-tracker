import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useSortieAudio } from '@/composables/useSortieAudio'
import { useVerrouDEcran } from '@/composables/useVerrouDEcran'

/**
 * Un bip qui a ce retard sur son heure, ou davantage, ne se joue plus (#2113) :
 * il partirait à contretemps, après une page suspendue ou une minuterie que
 * l'arrière-plan a retenue. Une seconde, comme le délai qu'une sortie arrêtée a
 * pour reprendre (`DELAI_DE_REPRISE_MS` de `useSortieAudio`).
 */
export const RETARD_MAX_D_UN_BIP_MS = 1000

/** Le bip de chacune des trois dernières secondes d'une phase. */
const BIP_DU_DECOMPTE = { frequence: 440, duree: 100 }

/** Le bip d'un changement de phase. */
const BIP_DU_CHANGEMENT = { frequence: 880, duree: 400 }

/** Le bip de la fin, avec celui du dernier changement de phase. */
const BIP_DE_LA_FIN = { frequence: 1200, duree: 600 }

/**
 * Une durée réglée en secondes, en millisecondes entières ; vide, nulle ou
 * négative, elle ne dure rien.
 *
 * @param {number|string|null|undefined} secondes
 * @returns {number}
 */
const enMillisecondes = (secondes) => {
    const valeur = Number(secondes)

    return Number.isFinite(valeur) && valeur > 0 ? Math.round(valeur * 1000) : 0
}

/**
 * Le programme d'une configuration, en temps de marche (ms) : l'échauffement,
 * puis chaque tour, un travail suivi d'un repos, sauf le dernier, sans repos.
 * Rien ne se calcule d'avance tour par tour : l'étape d'un instant se lit par
 * `etapeA`, si bien qu'un aperçu aux tours démesurés part aussitôt.
 *
 * @param {{ work: number, rest: number, rounds: number, warmup: number }} config
 */
const programmeDe = (config) => {
    const echauffement = config.warmup > 0 ? enMillisecondes(config.warmup) : 0
    const travail = enMillisecondes(config.work)
    const repos = enMillisecondes(config.rest)
    const tours = Math.max(1, Math.floor(Number(config.rounds)) || 1)

    return {
        echauffement,
        travail,
        tours,
        cycle: travail + repos,
        duree: echauffement + tours * travail + (tours - 1) * repos,
    }
}

/**
 * L'étape en cours à `marche` : sa phase, son tour, son début et sa fin, en
 * temps de marche ; `null` une fois le programme fini. Une étape qui ne dure
 * rien (un repos de 0 s) n'est jamais l'étape en cours : le travail suivant
 * enchaîne.
 *
 * @param {ReturnType<typeof programmeDe>} programme
 * @param {number} marche
 * @returns {{ phase: 'warmup'|'work'|'rest', tour: number, debut: number, fin: number }|null}
 */
const etapeA = (programme, marche) => {
    const { echauffement, travail, cycle, duree } = programme

    if (marche < echauffement) {
        return { phase: 'warmup', tour: 1, debut: 0, fin: echauffement }
    }

    if (marche >= duree) {
        return null
    }

    /*
     * Sous la durée du programme, un tour au moins dure : `cycle` n'est pas
     * nul, et le tour se lit sans borne, le dernier, sans repos, finissant
     * avant le suivant.
     */
    const indice = Math.floor((marche - echauffement) / cycle)
    const debutDuTour = echauffement + indice * cycle

    if (marche < debutDuTour + travail) {
        return { phase: 'work', tour: indice + 1, debut: debutDuTour, fin: debutDuTour + travail }
    }

    return { phase: 'rest', tour: indice + 1, debut: debutDuTour + travail, fin: debutDuTour + cycle }
}

/**
 * Les bips dont l'heure tombe dans `]depuis, jusqua]`, dans l'ordre : le
 * décompte des trois dernières secondes d'une étape (sauf celle où l'étape
 * commence), son changement de phase à sa fin, et la fin du programme avec
 * le dernier changement. Seules les étapes de la fenêtre se lisent.
 *
 * @param {ReturnType<typeof programmeDe>} programme
 * @param {number} depuis
 * @param {number} jusqua
 * @returns {Array<{ frequence: number, duree: number }>}
 */
const bipsEntre = (programme, depuis, jusqua) => {
    const bips = []
    const ajouter = (a, bip) => {
        if (a > depuis && a <= jusqua) {
            bips.push(bip)
        }
    }

    let etape = etapeA(programme, depuis)

    while (etape !== null && etape.debut <= jusqua) {
        for (const secondesRestantes of [3, 2, 1]) {
            const a = etape.fin - secondesRestantes * 1000

            if (a > etape.debut) {
                ajouter(a, BIP_DU_DECOMPTE)
            }
        }

        ajouter(etape.fin, BIP_DU_CHANGEMENT)

        if (etape.fin === programme.duree) {
            ajouter(etape.fin, BIP_DE_LA_FIN)
        }

        etape = etapeA(programme, etape.fin)
    }

    return bips
}

/**
 * Le minuteur d'intervalles : quatre phases (échauffement, travail, repos,
 * terminé), les bips du décompte et des transitions, et l'habillage de la phase
 * en cours. La page ne garde que ses onglets et ses préréglages.
 *
 * Il lit l'horloge (#2113). Il retient son temps de marche : le temps écoulé
 * depuis la dernière reprise (`Date.now()`), plus celui d'avant la dernière
 * pause, le temps passé en pause ne comptant pas. La phase, le tour et le
 * temps restant s'en tirent à chaque minuterie, réglée sur la seconde pleine
 * suivante, et au retour de la page (`visibilitychange`). Il comptait des
 * tics : une page suspendue (l'écran verrouillé, l'application en
 * arrière-plan) ou une minuterie espacée par le navigateur le figeait, et il
 * repartait d'où il s'était figé. Un bip dont l'heure est passée d'au moins
 * `RETARD_MAX_D_UN_BIP_MS` ne se rejoue pas ; l'affichage et la suite, si.
 *
 * Il garde l'écran allumé tant qu'il tourne (`useVerrouDEcran`), demandé dans
 * le geste de « Démarrer », relâché en pause, à la fin, à la réinitialisation
 * et au démontage, et redemandé au retour de la page, que le navigateur le
 * relâche en la cachant.
 *
 * Les bips passent par `useSortieAudio` (#2156) : « Démarrer », qui est aussi
 * le geste de la reprise après une pause, ouvre ou reprend la sortie (neuve,
 * `suspended`, `interrupted` après un appel ou Siri, ou recréée si elle est
 * `closed`), et chaque bip s'y joue ensuite depuis la minuterie, sans jamais
 * partir à contretemps sur une sortie arrêtée. La sortie se ferme au démontage
 * du composant qui appelle le minuteur.
 */
export function useMinuteurDIntervalles() {
    const timerConfig = ref({
        name: 'Tabata',
        work: 20,
        rest: 10,
        rounds: 8,
        warmup: 10,
    })

    const status = ref('idle') // 'idle', 'running', 'paused', 'finished'
    const phase = ref('idle') // 'idle', 'warmup', 'work', 'rest', 'finished'
    const timeLeft = ref(0)
    const currentRound = ref(1)

    const { preparer, jouer } = useSortieAudio()
    const verrouDEcran = useVerrouDEcran()

    /** Le programme de la configuration chargée. */
    let programme = programmeDe(timerConfig.value)

    /** Le temps de marche accumulé jusqu'à la dernière pause, en ms. */
    let marcheAvantLaReprise = 0

    /** L'heure de la dernière reprise (`Date.now()`), `null` hors de la marche. */
    let repriseA = null

    /** Le temps de marche jusqu'auquel les bips ont été joués ou abandonnés. */
    let bipsJusqua = 0

    /** La minuterie qui recale le minuteur à la seconde pleine suivante. */
    let minuterie = null

    /**
     * Le temps de marche à l'instant `maintenant`. Une horloge remise avant
     * la reprise ne le fait pas descendre sous la marche d'avant la pause.
     *
     * @param {number} maintenant
     * @returns {number}
     */
    const tempsDeMarche = (maintenant) =>
        repriseA === null ? marcheAvantLaReprise : marcheAvantLaReprise + Math.max(0, maintenant - repriseA)

    /**
     * Un bip : une sinusoïde à `freq` Hz, qui s'éteint en `duration` ms. 440 Hz
     * pendant 100 ms pour chacune des trois dernières secondes d'une phase,
     * 880 Hz pendant 400 ms au changement de phase, 1 200 Hz pendant 600 ms à
     * la fin. Sans sortie ouverte par un geste, ou sur une sortie qui ne
     * repart pas à temps, il ne se joue pas ; un son qui échoue ne fait rien
     * tomber (`useSortieAudio`).
     *
     * @param {number} freq
     * @param {number} duration
     */
    const playBeep = (freq = 440, duration = 200) => {
        jouer((ctx) => {
            const osc = ctx.createOscillator()
            const gain = ctx.createGain()

            osc.connect(gain)
            gain.connect(ctx.destination)

            osc.type = 'sine'
            osc.frequency.value = freq

            osc.start()
            gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + duration / 1000)
            osc.stop(ctx.currentTime + duration / 1000)
        })
    }

    const arreterLaMinuterie = () => {
        if (minuterie !== null) {
            clearTimeout(minuterie)
            minuterie = null
        }
    }

    /**
     * Joue chaque bip tombé depuis le recalage précédent, sauf celui qui a
     * une seconde de retard ou plus et partirait à contretemps : une page
     * suspendue ne rejoue pas, à son retour, les bips qu'elle a manqués. La
     * fenêtre ne remonte jamais plus loin : après dix jours d'arrière-plan,
     * seules les étapes de la dernière seconde se lisent.
     *
     * @param {number} marche
     */
    const sonnerJusqua = (marche) => {
        const depuis = Math.max(bipsJusqua, marche - RETARD_MAX_D_UN_BIP_MS)

        for (const bip of bipsEntre(programme, depuis, marche)) {
            playBeep(bip.frequence, bip.duree)
        }

        bipsJusqua = Math.max(bipsJusqua, marche)
    }

    const terminer = () => {
        status.value = 'finished'
        phase.value = 'finished'
        timeLeft.value = 0
        currentRound.value = programme.tours
        marcheAvantLaReprise = programme.duree
        repriseA = null
        arreterLaMinuterie()
        verrouDEcran.relacher()
    }

    /**
     * Lit l'horloge : la phase, le tour et le temps restant de l'instant
     * présent, et les bips qui viennent de tomber. Puis se reprogramme à la
     * seconde pleine suivante du temps de marche, pour que l'horloge affichée
     * change à l'heure, après une reprise comme après un retour de la page.
     */
    const recaler = () => {
        if (status.value !== 'running') {
            return
        }

        const marche = tempsDeMarche(Date.now())
        sonnerJusqua(marche)

        const etape = etapeA(programme, marche)

        if (etape === null) {
            terminer()

            return
        }

        phase.value = etape.phase
        currentRound.value = etape.tour
        timeLeft.value = Math.ceil((etape.fin - marche) / 1000)

        arreterLaMinuterie()
        minuterie = setTimeout(recaler, 1000 - (marche % 1000))
    }

    const resetRunner = () => {
        arreterLaMinuterie()
        verrouDEcran.relacher()
        programme = programmeDe(timerConfig.value)
        marcheAvantLaReprise = 0
        repriseA = null
        bipsJusqua = 0
        status.value = 'idle'
        phase.value = 'idle'
        timeLeft.value = timerConfig.value.warmup > 0 ? timerConfig.value.warmup : timerConfig.value.work
        currentRound.value = 1
    }

    /** Prend une configuration (nom, travail, repos, tours, échauffement) et se remet au départ. */
    const charger = (config) => {
        timerConfig.value = config
        resetRunner()
    }

    /**
     * Démarre, ou reprend après une pause. C'est le geste de « Démarrer » :
     * la sortie s'y ouvre ou s'y reprend, avant toute autre chose, et
     * `preparer` ne lève jamais, si bien qu'un navigateur qui refuse la sortie
     * laisse le minuteur partir, sans son. Le verrou d'écran s'y demande.
     */
    const startTimer = () => {
        preparer()

        if (status.value === 'running') {
            return
        }

        if (status.value === 'finished') {
            resetRunner()
        }

        if (status.value === 'idle') {
            programme = programmeDe(timerConfig.value)
        }

        repriseA = Date.now()
        status.value = 'running'
        verrouDEcran.garder()
        recaler()
    }

    /**
     * Met en pause : le temps de marche se fige à l'instant du geste, fraction
     * de seconde comprise, et le verrou d'écran se rend.
     */
    const pauseTimer = () => {
        recaler()

        if (status.value !== 'running') {
            return
        }

        marcheAvantLaReprise = tempsDeMarche(Date.now())
        repriseA = null
        status.value = 'paused'
        arreterLaMinuterie()
        verrouDEcran.relacher()
    }

    const toggleTimer = () => {
        if (status.value === 'running') {
            pauseTimer()
        } else {
            startTimer()
        }
    }

    // Format time mm:ss
    const formattedTime = computed(() => {
        const minutes = Math.floor(timeLeft.value / 60)
        const seconds = timeLeft.value % 60
        return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
    })

    const phaseColor = computed(() => {
        switch (phase.value) {
            case 'warmup':
                return 'text-accent-info-deep'
            case 'work':
                return 'text-accent-primary-deep'
            case 'rest':
                return 'text-accent-state-deep'
            default:
                return 'text-text-main'
        }
    })

    const phaseBg = computed(() => {
        switch (phase.value) {
            case 'warmup':
                return 'bg-accent-info/10 border-accent-info/20'
            case 'work':
                return 'bg-accent-primary/10 border-accent-primary/20'
            case 'rest':
                return 'bg-accent-state/10 border-accent-state/20'
            default:
                return 'bg-surface-sunken border-surface-sunken'
        }
    })

    const phaseLabel = computed(() => {
        switch (phase.value) {
            case 'warmup':
                return 'ÉCHAUFFEMENT'
            case 'work':
                return 'TRAVAIL'
            case 'rest':
                return 'REPOS'
            case 'finished':
                return 'TERMINÉ'
            default:
                return 'PRÊT'
        }
    })

    /** Au retour de la page, le minuteur se recale sur l'horloge : une page suspendue n'a fait partir aucune minuterie. */
    const surLaVisibilite = () => {
        if (document.visibilityState === 'visible') {
            recaler()
        }
    }

    onMounted(() => {
        resetRunner()
        document.addEventListener('visibilitychange', surLaVisibilite)
    })

    onUnmounted(() => {
        arreterLaMinuterie()
        document.removeEventListener('visibilitychange', surLaVisibilite)
    })

    return {
        timerConfig,
        status,
        phase,
        timeLeft,
        currentRound,
        formattedTime,
        phaseColor,
        phaseBg,
        phaseLabel,
        charger,
        resetRunner,
        toggleTimer,
        startTimer,
        pauseTimer,
    }
}
