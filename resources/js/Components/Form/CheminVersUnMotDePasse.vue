<script setup>
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * Comment un compte relié à un fournisseur de connexion obtient un mot de
 * passe, dans un formulaire du profil qui demande le mot de passe actuel :
 * le changement d'adresse, le changement du mot de passe et la fenêtre de
 * suppression du compte (#2104).
 *
 * La connexion sociale crée le compte avec un mot de passe tiré au hasard,
 * que personne ne connaît. Le chemin passe par « Mot de passe oublié ? »,
 * ouvert aux seuls invités, d'où la déconnexion d'abord ; le lien part à
 * l'adresse actuelle, lue sur la page (`auth.user`). Il ne parvient pas à
 * certaines adresses qu'un compte d'avant #2075 peut porter, que la
 * messagerie n'écrit pas telles quelles ou que la demande du lien refuse :
 * l'encadré ne le promet pas, et renvoie à un administrateur. C'est le texte
 * des refus du serveur
 * (`DonneLeCheminVersUnMotDePasse`), avec l'adresse en plus : chaque
 * formulaire cache l'encadré devant le refus de son champ, qui le dit déjà.
 * Sans fournisseur, rien ne s'affiche.
 */
const props = defineProps({
    /** Le fournisseur relié au compte (« Google »…), ou null pour un compte à mot de passe seul. */
    fournisseurDeConnexion: {
        type: String,
        default: null,
    },
    /** Le lien « mot de passe oublié » parvient-il à l'adresse actuelle (`User::peutRecevoirLeLienDeReinitialisation()`) ? */
    peutRecevoirLeLienDeReinitialisation: {
        type: Boolean,
        default: true,
    },
})

const page = usePage()

const adresseActuelle = computed(() => page.props.auth?.user?.email ?? '')
</script>

<template>
    <div
        v-if="props.fournisseurDeConnexion"
        class="bg-accent-info/10 rounded-xl p-3"
        data-testid="profile-social-password-help"
    >
        <p v-if="props.peutRecevoirLeLienDeReinitialisation" class="text-accent-info-deep text-sm">
            Ton compte est relié à {{ props.fournisseurDeConnexion }}. Si tu n’as jamais choisi de mot de passe,
            choisis-en un d’abord : déconnecte-toi, puis « Mot de passe oublié ? » sur la page de connexion. Le lien
            part à ton adresse actuelle, {{ adresseActuelle }}.
        </p>
        <p v-else class="text-accent-info-deep text-sm">
            Ton compte est relié à {{ props.fournisseurDeConnexion }}. Si tu n’as jamais choisi de mot de passe, aucun
            lien ne peut t’en donner un : notre messagerie ne sait pas écrire à ton adresse actuelle,
            {{ adresseActuelle }}. Demande à un administrateur de l’application de la changer.
        </p>
    </div>
</template>
