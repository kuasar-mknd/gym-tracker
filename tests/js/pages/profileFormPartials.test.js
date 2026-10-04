import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'

const formPut = vi.fn()
const formPatch = vi.fn()

/**
 * The two profile partials are where a failed save has to put the cursor back.
 * `UpdatePasswordForm` decides, from the errors it got, which of the three boxes
 * to empty and which one to focus — and both halves of that are invisible to a
 * test that only checks the request went out.
 *
 * `useForm` is therefore reactive and its `reset` restores initial values the
 * way Inertia's does, so a partial reset and a full one look different. The
 * inputs are the real `GlassInput`, because the focus call the partial makes
 * goes through the component's exposed `focus()` down to the actual element.
 */
let currentPage = { props: { auth: { user: {} } } }
let openRequest = null

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: {
            props: { href: { type: String, default: '' }, method: { type: String, default: 'get' } },
            template: '<a :href="href" :data-method="method"><slot /></a>',
        },
        usePage: () => currentPage,
        useForm: (data) => {
            const initial = structuredClone(data)

            const start =
                (recorder) =>
                (...args) => {
                    form.processing = true
                    openRequest = form

                    return recorder(...args)
                }

            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                recentlySuccessful: false,
                put: start(formPut),
                patch: start(formPatch),
                reset: (...fields) => {
                    const targets = fields.length > 0 ? fields : Object.keys(initial)
                    targets.forEach((field) => {
                        form[field] = initial[field]
                    })
                },
            })

            return form
        },
    }
})

import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue'
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm.vue'
import ProfileEdit from '@/Pages/Profile/Edit.vue'
import { layoutStub, passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

beforeEach(() => {
    vi.clearAllMocks()
    openRequest = null
    currentPage = { props: { auth: { user: {} } } }
})

const mountPartial = (component, props = {}) =>
    mount(component, {
        props,
        attachTo: document.body,
        global: {
            directives: { press: {} },
            mocks: { route: globalThis.route },
            stubs: { GlassCard: passesSlot },
        },
    })

const submit = async (wrapper) => {
    await wrapper.find('form').trigger('submit')
}

const settleRequest = async () => {
    openRequest.processing = false
    await nextTick()
}

/** The label above the input that currently holds the cursor. */
const focusedFieldLabel = (wrapper) =>
    wrapper
        .findAll('label')
        .find((label) => label.attributes('for') === document.activeElement?.id)
        ?.text()

/**
 * Types into the box sitting under a given label, so the field is identified the
 * way a user identifies it rather than by its position in the form.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper
 * @param {string} labelText - The visible label, without its required asterisk.
 * @param {string} value - What to type.
 * @returns {Promise<void>}
 */
const typeInto = async (wrapper, labelText, value) => {
    const label = wrapper.findAll('label').find((candidate) => candidate.text().replace(/\s*\*$/, '') === labelText)

    await wrapper.find(`#${label.attributes('for')}`).setValue(value)
}

describe('UpdatePasswordForm', () => {
    const filled = () => {
        const wrapper = mountPartial(UpdatePasswordForm)
        Object.assign(wrapper.vm.form, {
            current_password: 'ancien',
            password: 'nouveau',
            password_confirmation: 'nouveau-bis',
        })

        return wrapper
    }

    const fields = (wrapper) => [
        wrapper.vm.form.current_password,
        wrapper.vm.form.password,
        wrapper.vm.form.password_confirmation,
    ]

    it('range chacun des trois mots de passe sous sa propre étiquette', async () => {
        const wrapper = mountPartial(UpdatePasswordForm)

        await typeInto(wrapper, 'Mot de passe actuel', 'ancien')
        await typeInto(wrapper, 'Nouveau mot de passe', 'nouveau')
        await typeInto(wrapper, 'Confirmer le mot de passe', 'nouveau-bis')

        // Three boxes that look alike: the current one bound to the new one
        // would send the old password up as the replacement, and the form would
        // still look correctly filled in on screen.
        expect(fields(wrapper)).toEqual(['ancien', 'nouveau', 'nouveau-bis'])
    })

    it('met à jour par PUT, en gardant la position dans la page', async () => {
        const wrapper = filled()

        await submit(wrapper)

        // The partial sits halfway down a long settings page; without
        // preserveScroll a save throws the user back to the top of it.
        expect(formPut.mock.calls[0][0]).toBe('/password.update')
        expect(formPut.mock.calls[0][1].preserveScroll).toBe(true)
    })

    it('vide les trois champs après un enregistrement réussi', async () => {
        const wrapper = filled()

        await submit(wrapper)
        formPut.mock.calls[0][1].onSuccess()
        await nextTick()

        expect(fields(wrapper)).toEqual(['', '', ''])
    })

    it('sur un mot de passe refusé, ne vide que le nouveau et sa confirmation', async () => {
        const wrapper = filled()
        wrapper.vm.form.errors = { password: 'Trop court.' }

        await submit(wrapper)
        formPut.mock.calls[0][1].onError()
        await nextTick()

        // The current password was accepted; clearing it too makes the user
        // retype something the server never complained about.
        expect(fields(wrapper)).toEqual(['ancien', '', ''])
        expect(focusedFieldLabel(wrapper)).toBe('Nouveau mot de passe')
    })

    it('sur un mot de passe actuel refusé, ne vide que celui-là', async () => {
        const wrapper = filled()
        wrapper.vm.form.errors = { current_password: 'Incorrect.' }

        await submit(wrapper)
        formPut.mock.calls[0][1].onError()
        await nextTick()

        expect(fields(wrapper)).toEqual(['', 'nouveau', 'nouveau-bis'])
        expect(focusedFieldLabel(wrapper)).toBe('Mot de passe actuel')
    })

    it('ne vide rien quand l’échec ne porte sur aucun des deux', async () => {
        const wrapper = filled()
        wrapper.vm.form.errors = { autre: 'Requête refusée.' }

        await submit(wrapper)
        formPut.mock.calls[0][1].onError()
        await nextTick()

        // A handler that empties the boxes on any error at all costs the user
        // everything they typed over a rate limit or a dropped connection.
        expect(fields(wrapper)).toEqual(['ancien', 'nouveau', 'nouveau-bis'])
    })

    it('occupe le bouton tant que la requête est en vol, et le libère après', async () => {
        const wrapper = filled()
        const button = () => wrapper.find('[data-testid="update-password-button"]')

        expect(button().attributes('aria-busy')).toBe('false')

        await submit(wrapper)

        expect(button().attributes('aria-busy')).toBe('true')

        await settleRequest()

        expect(button().attributes('aria-busy')).toBe('false')
    })

    it('ne confirme l’enregistrement qu’une fois qu’il a eu lieu', async () => {
        const wrapper = filled()

        expect(wrapper.text()).not.toContain('Enregistré ✓')

        wrapper.vm.form.recentlySuccessful = true
        await nextTick()

        expect(wrapper.text()).toContain('Enregistré ✓')
    })
})

describe('UpdateProfileInformationForm', () => {
    const signedInAs = (user) => {
        currentPage = { props: { auth: { user } } }
    }

    const verified = { name: 'Sam', email: 'sam@example.com', email_verified_at: '2026-01-01T00:00:00Z' }
    const unverified = { name: 'Sam', email: 'sam@example.com', email_verified_at: null }

    const warning = "Ton adresse email n'est pas vérifiée."

    it('préremplit le formulaire avec le compte connecté', () => {
        signedInAs(verified)

        const wrapper = mountPartial(UpdateProfileInformationForm)

        expect(wrapper.find('input[type="text"]').element.value).toBe('Sam')
        expect(wrapper.find('input[type="email"]').element.value).toBe('sam@example.com')
    })

    it('range le nom et l’email dans leurs champs respectifs', async () => {
        signedInAs(verified)

        const wrapper = mountPartial(UpdateProfileInformationForm)

        await typeInto(wrapper, 'Nom', 'Samuel')
        await typeInto(wrapper, 'Email', 'nouveau@example.com')

        expect(wrapper.vm.form.name).toBe('Samuel')
        expect(wrapper.vm.form.email).toBe('nouveau@example.com')
    })

    it('enregistre par PATCH vers la route de profil', async () => {
        signedInAs(verified)

        const wrapper = mountPartial(UpdateProfileInformationForm)
        await submit(wrapper)

        expect(formPatch.mock.calls[0][0]).toBe('/profile.update')
    })

    it('n’avertit que si la vérification est exigée ET que l’email ne l’est pas', () => {
        signedInAs(unverified)
        const asked = mountPartial(UpdateProfileInformationForm, { mustVerifyEmail: true })
        const notAsked = mountPartial(UpdateProfileInformationForm, { mustVerifyEmail: false })

        signedInAs(verified)
        const alreadyVerified = mountPartial(UpdateProfileInformationForm, { mustVerifyEmail: true })

        // Both halves matter: on either one alone the banner shows up for people
        // who have nothing left to do, or hides from the people who do.
        expect(asked.text()).toContain(warning)
        expect(notAsked.text()).not.toContain(warning)
        expect(alreadyVerified.text()).not.toContain(warning)
    })

    it('renvoie l’email de vérification en POST', () => {
        signedInAs(unverified)

        const wrapper = mountPartial(UpdateProfileInformationForm, { mustVerifyEmail: true })
        const resend = wrapper.find('a[href="/verification.send"]')

        expect(resend.attributes('data-method')).toBe('post')
    })

    it('ne confirme le renvoi que pour le statut que Laravel envoie', () => {
        signedInAs(unverified)

        const sent = mountPartial(UpdateProfileInformationForm, {
            mustVerifyEmail: true,
            status: 'verification-link-sent',
        })
        const other = mountPartial(UpdateProfileInformationForm, {
            mustVerifyEmail: true,
            status: 'profile-updated',
        })

        expect(sent.text()).toContain('Un nouveau lien a été envoyé.')
        expect(other.text()).not.toContain('Un nouveau lien a été envoyé.')
    })

    it('occupe le bouton tant que la requête est en vol, et le libère après', async () => {
        signedInAs(verified)

        const wrapper = mountPartial(UpdateProfileInformationForm)
        const button = () => wrapper.find('[data-testid="save-profile-button"]')

        expect(button().attributes('aria-busy')).toBe('false')

        await submit(wrapper)

        expect(button().attributes('aria-busy')).toBe('true')

        await settleRequest()

        expect(button().attributes('aria-busy')).toBe('false')
    })

    /** L'étiquette du champ qui n'apparaît que lorsque l'adresse change. */
    const currentPasswordLabel = 'Mot de passe actuel'

    const hasField = (wrapper, labelText) =>
        wrapper.findAll('label').some((label) => label.text().replace(/\s*\*$/, '') === labelText)

    it('ne demande le mot de passe actuel que lorsque l’adresse change', async () => {
        signedInAs(verified)

        const wrapper = mountPartial(UpdateProfileInformationForm)

        expect(hasField(wrapper, currentPasswordLabel)).toBe(false)

        await typeInto(wrapper, 'Nom', 'Samuel')

        // Le serveur laisse passer le nom seul sans mot de passe : en demander un
        // quand même bloquerait les comptes qui n'en ont jamais eu.
        expect(hasField(wrapper, currentPasswordLabel)).toBe(false)

        await typeInto(wrapper, 'Email', 'nouveau@example.com')

        expect(hasField(wrapper, currentPasswordLabel)).toBe(true)
        expect(wrapper.find('[data-testid="profile-email-change-notice"]').text()).toContain(
            'Ton adresse actuelle sera prévenue du changement',
        )

        await typeInto(wrapper, currentPasswordLabel, 'secret')

        expect(wrapper.vm.form.current_password).toBe('secret')
        expect(wrapper.find('input[autocomplete="current-password"]').attributes('type')).toBe('password')
    })

    it('ne promet l’avis à l’adresse actuelle que si elle est vérifiée', async () => {
        signedInAs(unverified)

        const wrapper = mountPartial(UpdateProfileInformationForm)

        await typeInto(wrapper, 'Email', 'nouveau@example.com')

        // Le serveur ne prévient pas une adresse que personne n'a prouvée : le
        // formulaire ne l'annonce pas non plus.
        const notice = wrapper.find('[data-testid="profile-email-change-notice"]').text()

        expect(notice).not.toContain('sera prévenue')
        expect(notice).toContain('La nouvelle adresse devra être vérifiée.')
    })

    it('oublie le mot de passe saisi quand l’adresse revient à celle du compte', async () => {
        signedInAs(verified)

        const wrapper = mountPartial(UpdateProfileInformationForm)

        await typeInto(wrapper, 'Email', 'nouveau@example.com')
        await typeInto(wrapper, currentPasswordLabel, 'secret')
        await typeInto(wrapper, 'Email', 'sam@example.com')

        expect(hasField(wrapper, currentPasswordLabel)).toBe(false)
        expect(wrapper.vm.form.current_password).toBe('')
    })

    it('sur un mot de passe refusé, vide le champ et y remet le curseur', async () => {
        signedInAs(verified)

        const wrapper = mountPartial(UpdateProfileInformationForm)

        await typeInto(wrapper, 'Email', 'nouveau@example.com')
        await typeInto(wrapper, currentPasswordLabel, 'mauvais')
        wrapper.vm.form.errors = { current_password: 'Ce mot de passe ne correspond pas à ton compte.' }

        await submit(wrapper)
        formPatch.mock.calls[0][1].onError()
        await nextTick()

        expect(wrapper.vm.form.current_password).toBe('')
        expect(wrapper.vm.form.email).toBe('nouveau@example.com')
        expect(focusedFieldLabel(wrapper)).toBe(`${currentPasswordLabel} *`)
    })

    it('referme le champ une fois la nouvelle adresse enregistrée', async () => {
        currentPage = reactive({ props: { auth: { user: { ...verified } } } })

        const wrapper = mountPartial(UpdateProfileInformationForm)

        await typeInto(wrapper, 'Email', 'nouveau@example.com')
        await typeInto(wrapper, currentPasswordLabel, 'secret')
        await submit(wrapper)

        // Ce qu'Inertia rend après la redirection : le compte porte désormais la
        // nouvelle adresse, qui devient celle à laquelle comparer.
        currentPage.props.auth.user = { ...verified, email: 'nouveau@example.com', email_verified_at: null }
        await nextTick()

        expect(hasField(wrapper, currentPasswordLabel)).toBe(false)
        expect(wrapper.vm.form.current_password).toBe('')
    })

    it('dit à un compte relié à un fournisseur comment obtenir un mot de passe', async () => {
        signedInAs(verified)

        const social = mountPartial(UpdateProfileInformationForm, { fournisseurDeConnexion: 'Google' })
        const passwordOnly = mountPartial(UpdateProfileInformationForm)
        const help = (wrapper) => wrapper.find('[data-testid="profile-social-password-help"]')

        expect(help(social).exists()).toBe(false)

        await typeInto(social, 'Email', 'nouveau@example.com')
        await typeInto(passwordOnly, 'Email', 'nouveau@example.com')

        expect(help(social).text()).toContain('Ton compte est relié à Google.')
        expect(help(social).text()).toContain('« Mot de passe oublié ? »')
        expect(help(social).text()).toContain('Le lien part à ton adresse actuelle, sam@example.com.')
        expect(help(passwordOnly).exists()).toBe(false)

        // Une fois que le serveur a répondu, son message dit la même chose sous
        // le champ : l'encadré s'efface plutôt que de le répéter.
        social.vm.form.errors = { current_password: 'Ton compte est relié à Google. …' }
        await nextTick()

        expect(help(social).exists()).toBe(false)
    })

    it('ne confirme l’enregistrement qu’une fois qu’il a eu lieu', async () => {
        signedInAs(verified)

        const wrapper = mountPartial(UpdateProfileInformationForm)

        expect(wrapper.text()).not.toContain('Enregistré ✓')

        wrapper.vm.form.recentlySuccessful = true
        await nextTick()

        expect(wrapper.text()).toContain('Enregistré ✓')
    })
})

describe('Profile/Edit', () => {
    it('transmet le fournisseur de connexion au formulaire d’adresse', () => {
        const wrapper = mount(ProfileEdit, {
            props: { mustVerifyEmail: true, fournisseurDeConnexion: 'GitHub' },
            shallow: true,
            global: {
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot },
            },
        })

        // Sans lui, le formulaire ne saurait pas qu'il parle à un compte qui peut
        // n'avoir aucun mot de passe, et le refus du serveur resterait la seule
        // explication.
        expect(wrapper.findComponent(UpdateProfileInformationForm).props('fournisseurDeConnexion')).toBe('GitHub')
    })
})
