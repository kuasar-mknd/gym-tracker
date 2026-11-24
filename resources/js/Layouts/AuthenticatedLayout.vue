<script setup>
import { onMounted, onUnmounted } from 'vue'
import BottomNav from '@/Components/Navigation/BottomNav.vue'
import LiquidBackground from '@/Components/UI/LiquidBackground.vue'
import CelebrationModal from '@/Components/Achievements/CelebrationModal.vue'
import Dropdown from '@/Components/UI/Dropdown.vue'
import DropdownLink from '@/Components/UI/DropdownLink.vue'
import NavLink from '@/Components/Navigation/NavLink.vue'
import Cloche from '@/Components/Navigation/Cloche.vue'
import ActiveWorkoutBanner from '@/Components/Dashboard/ActiveWorkoutBanner.vue'
import BandeauDeMiseAJour from '@/Components/UI/BandeauDeMiseAJour.vue'
import BandeauHorsLigne from '@/Components/UI/BandeauHorsLigne.vue'
import ToastsDuLayout from '@/Components/UI/ToastsDuLayout.vue'
import QuestionDeDeconnexion from '@/Components/UI/QuestionDeDeconnexion.vue'
import MarqueDeLApplication from '@/Components/UI/MarqueDeLApplication.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, provide, watch } from 'vue'
import { CLE_DE_LA_SEANCE_EN_COURS } from '@/Utils/cleDeLaSeanceEnCours'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { useRaccourciClavier } from '@/composables/useRaccourciClavier'
import { rapprocherLAbonnementPush } from '@/composables/useAbonnementPush'
import { seDeconnecter } from '@/composables/useDeconnexion'
import { oublierLeReposDUneAutreSeance, reposAMontrer } from '@/Utils/reposEnCours'
import { ONGLETS, ongletDeLaRoute } from '@/Utils/ongletsDeLaBarre'
import { useSeanceEnCoursDeLAppareil } from '@/composables/useSeanceEnCoursDeLAppareil'
import { messageDesEcrituresEffacees, reprendreLesEcrituresEffacees } from '@/Utils/ecrituresEffacees'
import { messageDesEcrituresAbandonnees, reprendreLesEcrituresAbandonnees } from '@/Utils/ecrituresAbandonnees'
import { SESSION_FINIE_DANS_UN_AUTRE_ONGLET } from '@/Utils/ecrituresEffaceesALaDeconnexion'

const props = defineProps({
    pageTitle: {
        type: String,
        default: '',
    },
    showBack: {
        type: Boolean,
        default: false,
    },
    backRoute: {
        type: String,
        default: '',
    },
    liquidVariant: {
        type: String,
        default: 'default',
    },
    /**
     * La largeur du contenu sur grand écran.
     *
     * `large` (1280 px) convient à une grille de cartes. Un outil, lui, garde
     * une colonne de formulaire : étirée sur 1 216 px, elle donne des champs
     * d'un mètre de large et des tuiles de choix vides aux trois quarts
     * (#1802, #1817).
     */
    largeur: {
        type: String,
        default: 'large',
        validator: (valeur) => ['large', 'moyenne', 'etroite'].includes(valeur),
    },
    /**
     * La page pose elle-même son `<h1>` de bureau dans son contenu (les
     * statistiques, la bibliothèque, les compléments), caché jusqu'à `bureau:` :
     * le layout ne pose pas le sien (#2120).
     */
    titreDansLaPage: {
        type: Boolean,
        default: false,
    },
    /**
     * Le titre de l'en-tête mobile ne se voit pas : une salutation ou une carte
     * d'identité en tient lieu (l'Accueil, le Profil). Le `<h1>` reste, pour les
     * lecteurs d'écran, et l'en-tête garde la cloche des notifications.
     */
    titreMasque: {
        type: Boolean,
        default: false,
    },
    /**
     * La page pose déjà son retour dans son en-tête de bureau (les outils, la
     * fiche d'un exercice, le jeûne, la modification d'un objectif) : le
     * layout n'ajoute pas le sien, qui le doublerait (lot 8, #2434).
     */
    retourPropre: {
        type: Boolean,
        default: false,
    },
})

/*
 * Le retour des pages secondaires sur bureau : le bouton rond de l'en-tête mobile
 * n'y existe pas (`bureau:hidden`), et les quatre onglets ne ramènent qu'à la
 * racine de leur famille, pas à la page d'où l'on vient.
 */
const CLASSES_DU_RETOUR_DE_BUREAU =
    'text-text-muted hover:text-text-main min-h-touch focus-visible:ring-accent-primary bureau:inline-flex -ms-1 mb-1 hidden items-center gap-1 rounded-lg px-1 text-sm font-bold transition-colors focus-visible:ring-2 focus-visible:outline-none'

/*
 * Accueil et Profil n'ont pas de titre visible : la cloche y est dans la page,
 * dans la première carte (`Cloche`), sans barre qui lui réserve une rangée. Une
 * bande de la hauteur de la barre d'état garde l'horloge lisible quand le
 * contenu défile dessous.
 */
const clocheDansLaPage = computed(() => props.titreMasque && !props.showBack)

const LARGEURS = { large: 'max-w-7xl', moyenne: 'max-w-3xl', etroite: 'max-w-2xl' }

// `?` ouvre la liste des raccourcis, d'où qu'on soit — le seul moyen de la
// découvrir sans passer par le Profil.
useRaccourciClavier(
    (evenement) => evenement.key === '?',
    () => router.visit(route('shortcuts.index')),
)

const page = usePage()

const retourDeBureau = computed(() => props.showBack && !props.retourPropre)
const hrefDuRetour = computed(() => (props.backRoute ? route(props.backRoute) : 'javascript:history.back()'))

/*
 * La séance en cours sur l'appareil : celle du serveur, ou celle qu'une
 * création en file fait naître (#2049). La page de séance se reconnaît à son
 * composant : une séance démarrée sans réseau s'ouvre à l'adresse de sa clé,
 * que `route().current('workouts.show')` ne nomme pas.
 */
const { seanceEnCours: activeWorkout, ouvrir: ouvrirLaSeanceEnCours } = useSeanceEnCoursDeLAppareil({ page })
// L'accueil masque « Démarrer » d'après la même séance que celle qu'« Reprendre » rouvre.
provide(CLE_DE_LA_SEANCE_EN_COURS, activeWorkout)
// L'onglet de bureau allumé : les mêmes quatre que la barre du bas, par la même table (`ongletsDeLaBarre.js`).
const ongletAllume = () => ongletDeLaRoute(route().current())?.cle ?? null
const isWorkoutShow = computed(() => page.component === 'Workouts/Show')
// Le repos que la page de CETTE séance a laissé en partant (#2434) : le bandeau le montre à la place de la durée.
const reposLaisse = computed(() => reposAMontrer(page.props.auth?.user?.id, activeWorkout.value))
// Celui d'une autre séance (supprimée, ou quittée sans clôture), ou sans séance en cours : il ne reviendrait pas.
watch(activeWorkout, (seance) => oublierLeReposDUneAutreSeance(page.props.auth?.user?.id, seance), { immediate: true })
// L'accessoire de la séance en cours : sur toutes les pages sauf la séance elle-même, qui a sa barre d'outils.
const accessoireDeSeance = computed(() => activeWorkout.value !== null && !isWorkoutShow.value)

/*
 * L'abonnement push du navigateur rejoint le serveur à l'ouverture de
 * l'application, sur n'importe quelle page, et plus seulement sur le profil.
 * C'est la seule réparation sur iPhone, où le worker ne reçoit pas
 * `pushsubscriptionchange` (#1847). Une fois par chargement : le layout se
 * remonte à chaque page, la fonction s'en souvient.
 */
onMounted(() => rapprocherLAbonnementPush(page.props.auth?.user?.id))

/*
 * Ce que la file hors ligne a effacé sans pouvoir l'envoyer, dit une fois par
 * le toast d'erreur. D'abord les écritures que le chargement a effacées faute
 * de savoir à quel compte elles appartenaient (#1964) ; puis celles du compte
 * connecté qui ont attendu plus de trente jours et ont été abandonnées
 * (#2050), notées pour lui même quand un autre compte était connecté à ce
 * moment. Un message déjà affiché n'est pas remplacé : l'avis attend l'écran
 * suivant. L'abandon pendant que la page est ouverte (une PWA reprise après
 * des semaines) l'annonce par `sync:abandoned`.
 */
const direCeQuiAEteEfface = () => {
    if (page.props.flash?.error) {
        return
    }

    const effacees = reprendreLesEcrituresEffacees()
    const abandonnees = effacees > 0 ? null : reprendreLesEcrituresAbandonnees(page.props.auth?.user?.id)

    if (effacees === 0 && abandonnees === null) {
        return
    }

    const flash = page.props.flash ?? (page.props.flash = {})

    flash.error = effacees > 0 ? messageDesEcrituresEffacees(effacees) : messageDesEcrituresAbandonnees(abandonnees)
}

/*
 * Ce compte a quitté l'appareil depuis un autre onglet (#2050) : la session,
 * commune aux onglets, n'existe plus, et ce que la personne fait dans celui-ci
 * n'est plus enregistré ni gardé. Le toast d'erreur le dit, à la place de ce
 * qu'il montrait : rien n'est plus urgent.
 */
const direQueLaSessionAPrisFin = () => {
    const flash = page.props.flash ?? (page.props.flash = {})

    flash.error = SESSION_FINIE_DANS_UN_AUTRE_ONGLET
}

onMounted(() => {
    direCeQuiAEteEfface()
    window.addEventListener('sync:abandoned', direCeQuiAEteEfface)
    window.addEventListener('sync:compte-parti', direQueLaSessionAPrisFin)
})

onUnmounted(() => {
    window.removeEventListener('sync:abandoned', direCeQuiAEteEfface)
    window.removeEventListener('sync:compte-parti', direQueLaSessionAPrisFin)
})
</script>

<template>
    <div class="bg-surface-page relative min-h-dvh w-full">
        <a
            href="#main-content"
            class="accent-fill z-evitement pl-sure-4 pt-sure-2 absolute top-0 left-0 -translate-y-full rounded-br-xl pr-4 pb-2 font-bold transition-transform focus:translate-y-0 focus:ring-2 focus:outline-none"
        >
            Aller au contenu principal
        </a>

        <LiquidBackground :variant="liquidVariant" />

        <!--
            Local only, and deliberately not dismissible: a database behind the
            code fails at the write, never at the read, so the app looks healthy
            while every save is refused. That is exactly how an afternoon was
            lost to a column added days earlier — and the test suite cannot warn
            about it, since it migrates a fresh database on every run.
        -->
        <div
            v-if="$page.props.pending_migrations > 0"
            class="bg-accent-warning text-text-main z-alerte px-sure-4 pt-sure-2 sticky top-0 pb-2 text-center text-sm font-bold"
            role="alert"
            data-testid="pending-migrations-banner"
        >
            {{ $page.props.pending_migrations }} migration(s) en attente — lance
            <code class="bg-text-main/15 rounded px-1">sail artisan migrate</code>
            avant de continuer, sinon les enregistrements échoueront sans le dire.
        </div>

        <!-- Une nouvelle version propose de recharger, sans jamais le faire seule (#1967). -->
        <BandeauDeMiseAJour />

        <!-- Les messages de la page et ceux du réseau (#2048). -->
        <ToastsDuLayout />

        <!-- Avant une déconnexion qui perdrait des écritures pas encore envoyées (#2050). -->
        <QuestionDeDeconnexion />

        <!--
            La barre de bureau, à partir de 1 024 px de large (variante
            `bureau:` de app.css, #2057). Le nom du compte prend la place qui
            reste et s'abrège au-delà : sur plusieurs lignes, un nom long
            faisait déborder le bouton du compte hors de la barre.
        -->
        <nav
            aria-label="Navigation de bureau"
            class="bg-surface-page/80 border-surface-card/40 z-nav pt-sure-0 bureau:block sticky top-0 hidden border-b backdrop-blur-xl"
        >
            <div class="px-sure-8 mx-auto max-w-7xl">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <!-- Logo -->
                        <div class="flex shrink-0 items-center">
                            <Link :href="route('dashboard')" class="flex">
                                <MarqueDeLApplication disposition="barre" />
                            </Link>
                        </div>

                        <!-- Desktop Navigation Links -->
                        <div class="bureau:flex ms-8 hidden space-x-1" dusk="onglets-de-bureau">
                            <NavLink
                                v-for="onglet in ONGLETS"
                                :key="onglet.cle"
                                :href="route(onglet.route)"
                                :active="ongletAllume() === onglet.cle"
                            >
                                {{ onglet.nom }}
                            </NavLink>
                        </div>
                    </div>

                    <div class="bureau:flex ms-6 hidden min-w-0 items-center gap-3">
                        <!-- Notification Bell -->
                        <Link
                            :href="route('notifications.index')"
                            class="verre-regulier text-text-muted hover:text-text-main rounded-capsule relative flex size-11 shrink-0 items-center justify-center transition"
                            :aria-label="
                                $page.props.auth.user.unread_notifications_count > 0
                                    ? `Notifications (${$page.props.auth.user.unread_notifications_count} non lues)`
                                    : 'Notifications'
                            "
                        >
                            <GlassIcon name="notifications" />
                            <span
                                v-if="$page.props.auth.user.unread_notifications_count > 0"
                                class="accent-fill text-2xs absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full font-semibold"
                            >
                                {{ $page.props.auth.user.unread_notifications_count }}
                            </span>
                        </Link>

                        <!-- User Dropdown -->
                        <div class="relative min-w-0">
                            <Dropdown align="right" width="48">
                                <template #trigger="{ open }">
                                    <button
                                        type="button"
                                        aria-haspopup="true"
                                        :aria-expanded="open"
                                        class="text-text-main focus-visible:ring-accent-primary border-border bg-surface-card hover:bg-surface-card inline-flex max-w-full items-center gap-2 rounded-xl border px-4 py-2 text-sm font-bold shadow-sm transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                                    >
                                        <div
                                            class="bg-surface-glass-strong text-text-main flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                        >
                                            {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                                        </div>
                                        <span class="truncate">{{ $page.props.auth.user.name }}</span>
                                        <GlassIcon name="expand_more" size="sm" class="text-text-muted shrink-0" />
                                    </button>
                                </template>

                                <template #content>
                                    <DropdownLink :href="route('profile.edit')">
                                        <GlassIcon name="settings" size="sm" class="mr-2" />
                                        Paramètres
                                    </DropdownLink>
                                    <DropdownLink :href="route('achievements.index')">
                                        <GlassIcon name="emoji_events" size="sm" class="mr-2" />
                                        Badges
                                    </DropdownLink>
                                    <DropdownLink @click="seDeconnecter">
                                        <GlassIcon name="logout" size="sm" class="mr-2" />
                                        Déconnexion
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Mobile Header -->
        <header
            v-if="pageTitle || showBack"
            dusk="entete-mobile"
            class="bureau:hidden"
            :class="
                clocheDansLaPage
                    ? 'contents'
                    : 'bg-surface-page/40 z-collant px-sure-5 pt-sure-4 sticky top-0 flex items-center justify-between pb-4 backdrop-blur-xl'
            "
        >
            <div
                v-if="clocheDansLaPage"
                class="bg-surface-page/40 z-collant bureau:hidden left-sure-0 right-sure-0 pt-sure-0 pointer-events-none fixed top-0 h-(--safe-area-top) backdrop-blur-xl"
                aria-hidden="true"
            ></div>
            <div class="flex min-w-0 items-center gap-4">
                <Link
                    v-if="showBack"
                    v-press
                    :href="backRoute ? route(backRoute) : 'javascript:history.back()'"
                    class="verre-regulier text-text-muted hover:text-text-main rounded-capsule flex size-11 shrink-0 items-center justify-center transition-colors"
                    aria-label="Retour"
                >
                    <GlassIcon name="arrow_back" />
                </Link>
                <h1
                    v-if="pageTitle"
                    class="truncate"
                    :class="
                        titreMasque
                            ? 'sr-only'
                            : pageTitle.length > 12
                              ? 'titre-plat'
                              : 'titre-ecran max-[21rem]:titre-plat'
                    "
                >
                    {{ pageTitle }}
                </h1>
            </div>

            <div v-if="!clocheDansLaPage" class="flex items-center gap-2">
                <Cloche />
                <slot name="header-actions" />
            </div>
        </header>

        <!-- Desktop Page Heading Slot -->
        <header
            v-if="$slots.header"
            class="bg-surface-page/50 backdrop-blur-glass border-surface-card/40 bureau:block hidden border-b"
        >
            <div class="px-sure-4 sm:px-sure-6 lg:px-sure-8 mx-auto py-6" :class="LARGEURS[largeur]">
                <Link
                    v-if="retourDeBureau"
                    :href="hrefDuRetour"
                    :class="CLASSES_DU_RETOUR_DE_BUREAU"
                    dusk="retour-de-bureau"
                >
                    <GlassIcon name="arrow_back" size="sm" />
                    Retour
                </Link>
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <main
            id="main-content"
            tabindex="-1"
            class="px-sure-5 sm:px-sure-6 lg:px-sure-8 relative z-10 py-6 focus:outline-none"
            :class="[
                { 'pt-main-safe sm:pt-main-safe': (!pageTitle && !showBack) || clocheDansLaPage },
                accessoireDeSeance ? 'pb-main-safe-accessoire bureau:pb-main-safe' : 'pb-main-safe',
            ]"
        >
            <!--
                Le titre de niveau 1 du bureau (#2120). Celui de l'en-tête mobile
                se cache à partir de 1 024 px : sans celui-ci, une page dont
                l'en-tête de page (`#header`) ne porte pas de `<h1>` n'en avait
                aucun sur bureau, et un lecteur d'écran n'y trouvait pas de titre
                principal. Caché aux yeux, pour un rendu inchangé ; absent quand
                la page porte le sien, dans `#header` ou dans son contenu
                (`titre-dans-la-page`).
            -->
            <h1 v-if="pageTitle && !$slots.header && !titreDansLaPage" class="bureau:block sr-only hidden">
                {{ pageTitle }}
            </h1>
            <!--
                Sous le bandeau de mise à jour, et dans le flux plutôt que
                par-dessus : une page servie depuis sa copie hors ligne le
                reste tant qu'elle est affichée, et un bandeau posé sur la
                page en cacherait le haut (#2048).
            -->
            <div class="mx-auto" :class="LARGEURS[largeur]">
                <Link
                    v-if="retourDeBureau && !$slots.header"
                    :href="hrefDuRetour"
                    :class="CLASSES_DU_RETOUR_DE_BUREAU"
                    dusk="retour-de-bureau"
                >
                    <GlassIcon name="arrow_back" size="sm" />
                    Retour
                </Link>
                <BandeauHorsLigne />
            </div>

            <!--
                L'accessoire de séance (#2434) : fixé au-dessus de la barre du
                bas sous 1 024 px, dans le flux de la page sur bureau, où il
                n'y a pas de barre. Un seul exemplaire, que la page en cours
                soit l'Accueil, les séances ou une autre.
            -->
            <div v-if="accessoireDeSeance" class="bureau:mb-6 mx-auto" :class="LARGEURS[largeur]">
                <div class="au-dessus-du-menu left-sure-4 right-sure-4 z-flottant bureau:static bureau:max-w-xl fixed">
                    <ActiveWorkoutBanner
                        :workout="activeWorkout"
                        :repos="reposLaisse"
                        @ouvrir="ouvrirLaSeanceEnCours"
                    />
                </div>
            </div>

            <!--
                Ni transition ni clé sur l'adresse : le contenu se pose dès la
                réponse (#2148, étape 2a). Un changement de page remonte la
                page et ce layout, sans animation ; une transition `out-in`
                clée sur `$page.url` ne jouait donc que pour une visite qui
                garde l'état et change l'adresse (le mois du calendrier, la
                période des statistiques), et faisait attendre sa sortie
                (200 ms) avant de poser le nouveau contenu, puis son entrée
                (300 ms). Une clé seule remonterait encore chaque carte à la
                réponse, son animation d'entrée et le focus du bouton compris.
            -->
            <div class="mx-auto" :class="LARGEURS[largeur]">
                <slot :seance-en-cours="activeWorkout" />
            </div>
        </main>

        <!-- Bottom Navigation (mobile only) -->
        <BottomNav class="bureau:hidden" />

        <!-- Achievement Celebration Modal -->
        <CelebrationModal />
    </div>
</template>
