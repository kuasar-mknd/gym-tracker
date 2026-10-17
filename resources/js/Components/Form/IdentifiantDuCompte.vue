<script setup>
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * L'identifiant du compte connecté, pour les gestionnaires de mots de passe
 * (#2076), dans un formulaire du profil qui change le mot de passe ou l'adresse
 * du compte sans demander son identifiant : le changement du mot de passe, et
 * le changement d'adresse, dont le champ porte la nouvelle adresse, qui ne
 * remplace l'actuelle qu'une fois le lien suivi. Sans lui, un gestionnaire
 * rangeait le mot de passe sans savoir sous quel compte, ou sous la nouvelle
 * adresse. Un formulaire qui ne demande le mot de passe actuel que pour
 * confirmer une action (la suppression du compte, la confirmation du mot de
 * passe) ne le porte pas : il n'y a rien à y ranger.
 *
 * Chromium demande d'inclure ce que le contexte rend évident, « the username
 * field in a change password form », dans un champ caché ; 1Password, sur un
 * formulaire qui réinitialise un mot de passe, l'identifiant dans un champ
 * `autocomplete="username"` en `display: none`, et `aria-hidden` sur un champ
 * qu'on ne voit pas. Le champ est donc caché (`hidden`) : hors de l'écran, de
 * l'ordre de tabulation et de l'arbre d'accessibilité, il n'a pas besoin de
 * libellé. En `type="text"`, que la validation du navigateur ne juge pas : une
 * adresse ancienne qu'un champ `email` refuserait bloquerait l'envoi sans rien
 * montrer. Il n'est pas envoyé : il n'a pas de nom, et le formulaire d'Inertia
 * ne le connaît pas. L'adresse vient de la page (`auth.user`), aucune prop ne
 * l'ajoute ; les attributs reçus (`data-testid`) vont au champ.
 */
const page = usePage()

const identifiant = computed(() => page.props.auth?.user?.email ?? '')
</script>

<template>
    <input type="text" autocomplete="username" :value="identifiant" hidden aria-hidden="true" />
</template>
