import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

const formPost = vi.fn()
const haptic = vi.fn()

/**
 * The six guest pages are thin, and every one of them is thin in the same way:
 * a `useForm`, a submit handler, and a handful of `v-if`s. What they are not is
 * inert — each one decides which fields survive a failed attempt, and getting
 * that wrong is the difference between retyping a password and retyping the
 * whole form (or, on the reset page, between a working link and a dead one).
 *
 * So the `useForm` stand-in is reactive and behaves like Inertia's: `post`
 * raises `processing` and leaves it raised until the test settles the request,
 * and `reset` restores the initial values of the fields it is given — all of
 * them when given none. An inert bag of values would let a page that resets
 * nothing, or resets everything, pass identically.
 */
let openRequest = null

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: {
            props: { href: { type: String, default: '' }, method: { type: String, default: 'get' } },
            template: '<a :href="href" :data-method="method"><slot /></a>',
        },
        useForm: (data) => {
            const initial = structuredClone(data)

            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                recentlySuccessful: false,
                post: (...args) => {
                    form.processing = true
                    openRequest = form

                    return formPost(...args)
                },
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

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => haptic(...args) }))

import ConfirmPassword from '@/Pages/Auth/ConfirmPassword.vue'
import ForgotPassword from '@/Pages/Auth/ForgotPassword.vue'
import Login from '@/Pages/Auth/Login.vue'
import Register from '@/Pages/Auth/Register.vue'
import ResetPassword from '@/Pages/Auth/ResetPassword.vue'
import VerifyEmail from '@/Pages/Auth/VerifyEmail.vue'
import { passesSlot } from './pageStubs'

/** GuestLayout fills a card and a footer; a default-only stub drops the footer. */
const guestLayoutStub = { template: '<div><slot /><slot name="footer" /></div>' }

beforeAll(() => {
    globalThis.route = (name, params) => (params === undefined ? `/${name}` : `/${name}/${params}`)
})

beforeEach(() => {
    vi.clearAllMocks()
    openRequest = null
})

const mountPage = (component, { props = {}, page = {} } = {}) =>
    mount(component, {
        props,
        global: {
            directives: { press: {} },
            mocks: { route: globalThis.route, $page: { props: page } },
            stubs: { GuestLayout: guestLayoutStub, GlassCard: passesSlot },
        },
    })

/** The options object the page handed to `form.post`, callbacks included. */
const submitted = () => formPost.mock.calls[0]

const submit = async (wrapper) => {
    await wrapper.find('form').trigger('submit')
}

/** Ends the request Inertia would have had in flight. */
const settleRequest = async () => {
    openRequest.processing = false
    await nextTick()
}

const inputNamed = (wrapper, name) => wrapper.find(`input[name="${name}"]`)

/**
 * Types into the box sitting under a given label, going through the label's
 * `for` so the field is identified the way a user identifies it — by what it is
 * called on screen, not by its position in the form.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper
 * @param {string} labelText - The visible label.
 * @param {string} value - What to type.
 * @returns {Promise<void>}
 */
const typeInto = async (wrapper, labelText, value) => {
    // A required field's label carries a trailing asterisk for sighted users.
    const label = wrapper.findAll('label').find((candidate) => candidate.text().replace(/\s*\*$/, '') === labelText)

    await wrapper.find(`#${label.attributes('for')}`).setValue(value)
}

/** The social providers a page actually offers, in the order it offers them. */
const providersOffered = (wrapper) => wrapper.findAll('img[alt]').map((image) => image.attributes('alt'))

describe('Login', () => {
    it('poste vers la route de connexion', async () => {
        const wrapper = mountPage(Login)

        await submit(wrapper)

        expect(submitted()[0]).toBe('/login')
    })

    it('garde l’email et n’efface que le mot de passe quand la requête retombe', async () => {
        const wrapper = mountPage(Login)
        wrapper.vm.form.email = 'sam@example.com'
        wrapper.vm.form.password = 'hunter2'

        await submit(wrapper)
        submitted()[1].onFinish()
        await nextTick()

        // Resetting the whole form here makes a mistyped password cost the email
        // too, on every single retry.
        expect(wrapper.vm.form.email).toBe('sam@example.com')
        expect(wrapper.vm.form.password).toBe('')
        expect(inputNamed(wrapper, 'email').element.value).toBe('sam@example.com')
    })

    it('range ce qui est tapé dans le champ qui porte l’étiquette', async () => {
        const wrapper = mountPage(Login)

        await typeInto(wrapper, 'Email', 'sam@example.com')
        await typeInto(wrapper, 'Mot de passe', 'hunter2')

        // Label to field, one pair at a time: two boxes bound to the same value,
        // or bound to each other's, still leave a form that looks filled in.
        expect(wrapper.vm.form.email).toBe('sam@example.com')
        expect(wrapper.vm.form.password).toBe('hunter2')
    })

    it('vibre différemment selon que la connexion passe ou échoue', async () => {
        const wrapper = mountPage(Login)

        await submit(wrapper)
        submitted()[1].onSuccess()
        submitted()[1].onError()

        expect(haptic.mock.calls).toEqual([['success'], ['error']])
    })

    it('occupe le bouton tant que la requête est en vol, et le libère après', async () => {
        const wrapper = mountPage(Login)
        const button = () => wrapper.find('[data-testid="login-button"]')

        expect(button().attributes('aria-busy')).toBe('false')

        await submit(wrapper)

        // Asserted while the request is still open: a button never bound to
        // `processing` reads as idle here and idle again at the end, which an
        // after-the-fact check cannot tell apart from a working one.
        expect(button().attributes('aria-busy')).toBe('true')
        expect(button().attributes('disabled')).toBeDefined()

        await settleRequest()

        expect(button().attributes('aria-busy')).toBe('false')
        expect(button().attributes('disabled')).toBeUndefined()
    })

    it('n’offre le lien d’oubli que si le serveur dit que la route existe', () => {
        const withLink = mountPage(Login, { props: { canResetPassword: true } })
        const without = mountPage(Login, { props: { canResetPassword: false } })

        expect(withLink.text()).toContain('Mot de passe oublié ?')
        expect(without.text()).not.toContain('Mot de passe oublié ?')
    })

    it('affiche le message de statut du serveur, et rien quand il n’y en a pas', () => {
        expect(mountPage(Login, { props: { status: 'Lien envoyé.' } }).text()).toContain('Lien envoyé.')
        expect(mountPage(Login).text()).not.toContain('Lien envoyé.')
    })

    it('n’offre aucun fournisseur tant que le serveur ne l’a pas dit', () => {
        // Fail closed, not open. This used to default each provider to enabled,
        // and since the flag was never shared at all the default won every time
        // — which is how the Apple button stayed on the page while every click
        // on it answered 500. A button that cannot work is worse than a missing
        // one: it looks like a way in.
        const wrapper = mountPage(Login)

        expect(providersOffered(wrapper)).toEqual([])
    })

    it('offre les trois quand le serveur les déclare tous utilisables', () => {
        const wrapper = mountPage(Login, {
            page: { social_login_enabled: { google: true, github: true, apple: true } },
        })

        expect(providersOffered(wrapper)).toEqual(['Google', 'GitHub', 'Apple'])
    })

    it('ne retire que le fournisseur désactivé', () => {
        const wrapper = mountPage(Login, {
            page: { social_login_enabled: { google: false, github: true, apple: true } },
        })

        // Checked provider by provider rather than through the page text: a
        // single "Google is gone" assertion is just as happy when GitHub and
        // Apple have gone with it.
        expect(providersOffered(wrapper)).toEqual(['GitHub', 'Apple'])
    })

    it('retire chacun des trois quand il est le seul désactivé', () => {
        const github = mountPage(Login, {
            page: { social_login_enabled: { google: true, github: false, apple: true } },
        })
        const apple = mountPage(Login, {
            page: { social_login_enabled: { google: true, github: true, apple: false } },
        })

        expect(providersOffered(github)).toEqual(['Google', 'Apple'])
        expect(providersOffered(apple)).toEqual(['Google', 'GitHub'])
    })

    it('envoie chaque bouton social vers son propre fournisseur', () => {
        const wrapper = mountPage(Login, {
            page: { social_login_enabled: { google: true, github: true, apple: true } },
        })

        // Paired label by label: the three links all pointing at Google would
        // satisfy any assertion made on the set of hrefs alone.
        expect(
            wrapper
                .findAll('a[aria-label^="Continuer avec"]')
                .map((link) => [link.attributes('aria-label'), link.attributes('href')]),
        ).toEqual([
            ['Continuer avec Google', '/social.redirect/google'],
            ['Continuer avec GitHub', '/social.redirect/github'],
            ['Continuer avec Apple', '/social.redirect/apple'],
        ])
    })

    it('ne propose le raccourci de développement que sur une instance locale', () => {
        expect(
            mountPage(Login, { page: { is_local: true } })
                .find('a[href="/__dev-login"]')
                .exists(),
        ).toBe(true)
        expect(mountPage(Login).find('a[href="/__dev-login"]').exists()).toBe(false)
    })
})

describe('Register', () => {
    it('poste vers la route d’inscription', async () => {
        const wrapper = mountPage(Register)

        await submit(wrapper)

        expect(submitted()[0]).toBe('/register')
    })

    it('garde le nom et l’email, et n’efface que les deux mots de passe', async () => {
        const wrapper = mountPage(Register)
        Object.assign(wrapper.vm.form, {
            name: 'Sam',
            email: 'sam@example.com',
            password: 'hunter2',
            password_confirmation: 'hunter3',
        })

        await submit(wrapper)
        submitted()[1].onFinish()
        await nextTick()

        expect([wrapper.vm.form.name, wrapper.vm.form.email]).toEqual(['Sam', 'sam@example.com'])
        expect([wrapper.vm.form.password, wrapper.vm.form.password_confirmation]).toEqual(['', ''])
    })

    it('range chacun des quatre champs sous sa propre étiquette', async () => {
        const wrapper = mountPage(Register)

        await typeInto(wrapper, 'Nom', 'Sam')
        await typeInto(wrapper, 'Email', 'sam@example.com')
        await typeInto(wrapper, 'Mot de passe', 'hunter2')
        await typeInto(wrapper, 'Confirmer le mot de passe', 'hunter2-bis')

        // The two password boxes are the ones worth pairing: bound to the same
        // field they always agree, and the confirmation stops checking anything.
        expect(wrapper.vm.form).toMatchObject({
            name: 'Sam',
            email: 'sam@example.com',
            password: 'hunter2',
            password_confirmation: 'hunter2-bis',
        })
    })

    it('vibre différemment selon que l’inscription passe ou échoue', async () => {
        const wrapper = mountPage(Register)

        await submit(wrapper)
        submitted()[1].onSuccess()
        submitted()[1].onError()

        expect(haptic.mock.calls).toEqual([['success'], ['error']])
    })

    it('occupe le bouton tant que la requête est en vol', async () => {
        const wrapper = mountPage(Register)
        const button = () => wrapper.find('[data-testid="register-button"]')

        await submit(wrapper)

        expect(button().attributes('aria-busy')).toBe('true')

        await settleRequest()

        expect(button().attributes('aria-busy')).toBe('false')
    })
})

describe('ForgotPassword', () => {
    it('poste l’email vers la route d’envoi du lien', async () => {
        const wrapper = mountPage(ForgotPassword)

        await submit(wrapper)

        expect(submitted()[0]).toBe('/password.email')
    })

    it('affiche la confirmation du serveur, et rien sans elle', () => {
        expect(mountPage(ForgotPassword, { props: { status: 'Lien envoyé !' } }).text()).toContain('Lien envoyé !')
        expect(mountPage(ForgotPassword).text()).not.toContain('Lien envoyé !')
    })

    it('range l’email saisi dans le champ email', async () => {
        const wrapper = mountPage(ForgotPassword)

        await typeInto(wrapper, 'Email', 'sam@example.com')

        expect(wrapper.vm.form.email).toBe('sam@example.com')
    })
})

describe('ResetPassword', () => {
    const props = { token: 'jeton-du-mail', email: 'sam@example.com' }

    it('embarque le jeton et l’email reçus du lien dans le formulaire', () => {
        const wrapper = mountPage(ResetPassword, { props })

        expect(wrapper.vm.form.token).toBe('jeton-du-mail')
        expect(wrapper.vm.form.email).toBe('sam@example.com')
    })

    it('range les deux nouveaux mots de passe dans des champs distincts', async () => {
        const wrapper = mountPage(ResetPassword, { props })

        await typeInto(wrapper, 'Nouveau mot de passe', 'hunter2')
        await typeInto(wrapper, 'Confirmer le mot de passe', 'hunter2-bis')

        expect(wrapper.vm.form.password).toBe('hunter2')
        expect(wrapper.vm.form.password_confirmation).toBe('hunter2-bis')
    })

    it('laisse corriger l’email préchargé du lien', async () => {
        const wrapper = mountPage(ResetPassword, { props })

        await typeInto(wrapper, 'Email', 'autre@example.com')

        expect(wrapper.vm.form.email).toBe('autre@example.com')
    })

    it('poste vers la route de stockage du mot de passe', async () => {
        const wrapper = mountPage(ResetPassword, { props })

        await submit(wrapper)

        expect(submitted()[0]).toBe('/password.store')
    })

    it('garde le jeton et l’email corrigé quand la requête retombe, et n’efface que les mots de passe', async () => {
        const wrapper = mountPage(ResetPassword, { props })

        // The email is *corrected* rather than left as the link sent it, and
        // that is the whole point of the fixture: reset to the initial state
        // and reset of the two password fields only are indistinguishable on a
        // form nobody touched, since the token and the prefilled email are the
        // initial state. Correcting it first is what separates them — and a
        // page that resets everything sends the user back to the address the
        // link carried, which is the one that did not work.
        await typeInto(wrapper, 'Email', 'autre@example.com')
        wrapper.vm.form.password = 'hunter2'
        wrapper.vm.form.password_confirmation = 'hunter3'

        await submit(wrapper)
        submitted()[1].onFinish()
        await nextTick()

        // Both boxes, not just the first: a rejected password left sitting in
        // the confirmation field makes the next attempt fail on a mismatch the
        // user cannot see, since the box above it is now empty.
        expect(wrapper.vm.form.token).toBe('jeton-du-mail')
        expect(wrapper.vm.form.email).toBe('autre@example.com')
        expect([wrapper.vm.form.password, wrapper.vm.form.password_confirmation]).toEqual(['', ''])
    })
})

describe('ConfirmPassword', () => {
    it('poste vers la route de confirmation', async () => {
        const wrapper = mountPage(ConfirmPassword)

        await submit(wrapper)

        expect(submitted()[0]).toBe('/password.confirm')
    })

    it('vide le mot de passe quand la requête retombe', async () => {
        const wrapper = mountPage(ConfirmPassword)
        await typeInto(wrapper, 'Mot de passe', 'hunter2')

        expect(wrapper.vm.form.password).toBe('hunter2')

        await submit(wrapper)
        submitted()[1].onFinish()
        await nextTick()

        // Nothing else to keep here — leaving the password behind would hand the
        // next person at the machine a pre-filled security gate.
        expect(wrapper.vm.form.password).toBe('')
    })
})

describe('VerifyEmail', () => {
    it('poste vers la route de renvoi', async () => {
        const wrapper = mountPage(VerifyEmail)

        await submit(wrapper)

        expect(submitted()[0]).toBe('/verification.send')
    })

    it('ne confirme l’envoi que pour le statut que Laravel envoie vraiment', () => {
        const sent = mountPage(VerifyEmail, { props: { status: 'verification-link-sent' } })
        const other = mountPage(VerifyEmail, { props: { status: 'passwords.sent' } })
        const none = mountPage(VerifyEmail)

        expect(sent.text()).toContain('Un nouveau lien de vérification a été envoyé')
        expect(other.text()).not.toContain('Un nouveau lien de vérification a été envoyé')
        expect(none.text()).not.toContain('Un nouveau lien de vérification a été envoyé')
    })

    it('déconnecte en POST, pas par un lien que le navigateur peut préfetcher', () => {
        const wrapper = mountPage(VerifyEmail)
        const logout = wrapper.find('a[href="/logout"]')

        expect(logout.attributes('data-method')).toBe('post')
    })
})
