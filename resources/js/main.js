import '../css/app.css'
import { jeton } from '@/Utils/couleurs'

import { createInertiaApp, http, router } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { createApp, h } from 'vue'
import { ZiggyVue } from 'ziggy-js'
import { installerLeRapporteurDErreurs } from '@/Utils/rapporteurDErreurs'
import { installerLaGardeDeLHistorique } from '@/Utils/historiqueDuCompte'
import { inscrireLeWorker } from '@/Utils/miseAJourDuWorker'
import { installerLaRouteGlobale } from '@/Utils/routeGlobale'
import { installerLesVisitesHorsLigne } from '@/Utils/visitesHorsLigne'
import { installerLaSondeDuServeur } from '@/Utils/sondeDuServeur'
import { installerLEcritureDesCopies } from '@/Utils/ecritureDesCopies'
import { installerLePrechauffage } from '@/Utils/prechauffage'
import { oublierLesCopiesDUnAutreTitulaire } from '@/Utils/copiesHorsLigne'
import { installerLesMessagesDuWorker } from '@/Utils/messagesDuWorker'
import { demarrerHorsLigne, estLaCoquille } from '@/Utils/demarrageHorsLigne'
import { vPress } from './directives/vPress'
import { registerSW } from 'virtual:pwa-register'

/*
 * Une page « hors ligne » servie au délai apprend du worker la redirection
 * arrivée ensuite, et oublie les copies si la session a pris fin (#2048).
 * D'emblée, avant même le démarrage dans la coquille : le navigateur délivre
 * les messages du worker dès la fin de l'analyse du document, à qui écoute
 * déjà.
 */
if (typeof window !== 'undefined') {
    installerLesMessagesDuWorker()
}

/**
 * Ce que l'application installe, puis son montage. Dans un document du
 * serveur, tout de suite ; dans la coquille hors ligne, une fois la page à
 * monter écrite dans le document (`demarrerHorsLigne`, plus bas).
 */
const demarrerLApplication = () => {
    /*
     * Le worker, et ce qu'une nouvelle version fait d'une page ouverte : rien
     * sans un geste, puis la navigation suivante en entier, si le serveur
     * répond (#1967). Voir le module.
     */
    if (typeof window !== 'undefined') {
        inscrireLeWorker({ registerSW, routeur: router, http })

        /*
         * Une page de compte ne se relit plus dans l'historique d'un onglet après
         * le départ du compte, quel que soit l'onglet où il est parti (#1965), et
         * les copies hors ligne d'un compte s'oublient quand l'appareil change de
         * titulaire (#2048). Avant `createInertiaApp`, qui lit l'historique dès
         * son démarrage.
         */
        installerLaGardeDeLHistorique({ routeur: router, surUnAutreTitulaire: oublierLesCopiesDUnAutreTitulaire })

        /*
         * Une visite sans réseau le dit, au lieu de ne rien faire, et une visite
         * qui n'aboutit pas au bout de huit secondes propose de l'annuler (#2048).
         */
        installerLesVisitesHorsLigne({ routeur: router })

        /*
         * Tant que le serveur ne répond pas, la page lui demande la sonde au
         * retour du réseau, puis de loin en loin : ce qui attendait le serveur se
         * réactive sans un geste (#2048).
         */
        installerLaSondeDuServeur()

        /*
         * La page garde une copie chiffrée des pages de salle qu'elle reçoit du
         * réseau, page initiale comprise, pour un seul compte (#2048). Avant
         * `createInertiaApp`, qui ne dit rien de la page initiale.
         */
        const ecritureDesCopies = installerLEcritureDesCopies({ routeur: router })

        /*
         * Après une vraie réponse, la page demande au serveur, huit pages au plus
         * et l'une après l'autre, les pages du noyau de salle qui n'ont pas de
         * copie ou dont la copie a vieilli, une fois par demi-heure (#2048).
         */
        installerLePrechauffage({ routeur: router, ecriture: ecritureDesCopies })
    }

    // Expose router for testing (Dusk)
    window.Inertia = router

    // Les pages appellent route() en globale ; @routes n'écrit plus que la table.
    installerLaRouteGlobale()

    const appName = import.meta.env.VITE_APP_NAME || 'GymTracker'

    createInertiaApp({
        title: (title) => `${title} - ${appName}`,
        resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
        setup({ el, App, props, plugin }) {
            const app = createApp({ render: () => h(App, props) })
                .use(plugin)
                /**
                 * Sans configuration : ZiggyVue lit la table globale que `@routes`
                 * écrit dans la page. La passer en prop Inertia envoyait toute la
                 * table une seconde fois — 34 Ko par page, puis de nouveau en JSON
                 * à chaque navigation Inertia — pour une table identique.
                 */
                .use(ZiggyVue)

            // Les erreurs du navigateur restent chez nous : voir le rapporteur.
            installerLeRapporteurDErreurs(app)

            // Register custom directives
            app.directive('press', vPress)

            return app.mount(el)
        },
        progress: {
            /*
             * La barre de chargement d'Inertia. Elle veut une valeur, pas une
             * classe — on la prend donc dans la charte au démarrage, une seule fois.
             */
            color: jeton('text-muted'),
        },
    })
}

/*
 * Dans la coquille hors ligne, rien ne démarre avant elle : elle vérifie la clé
 * du témoin, le titulaire de l'appareil et le contrat, puis écrit dans le
 * document la page à monter, ou laisse son contenu statique, qui se recharge au
 * retour du réseau (#2048, partie F).
 */
if (estLaCoquille()) {
    demarrerHorsLigne().then(({ montee }) => {
        if (montee) {
            demarrerLApplication()
        }
    })
} else {
    demarrerLApplication()
}
