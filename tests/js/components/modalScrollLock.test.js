import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import Modal from '@/Components/UI/Modal.vue'

/**
 * jsdom does not implement <dialog>, so these stand in for it. They reproduce
 * the two behaviours that matter here: close() flips `open` and fires a `close`
 * event, and the browser may fire that event without the app asking — Escape on
 * a modal dialog is a close request handled by the browser itself.
 *
 * Teleport is deliberately left unstubbed: the stub rebuilds the element on
 * every render, which hides exactly the kind of state this file is about.
 */
beforeAll(() => {
    HTMLDialogElement.prototype.showModal = function showModal() {
        this.open = true
    }
    HTMLDialogElement.prototype.close = function close() {
        if (!this.open) {
            return
        }

        this.open = false
        this.dispatchEvent(new Event('close'))
    }
})

/** The panel is teleported to <body>, so it is not inside the wrapper's tree. */
const mountModal = (props = {}) => {
    const before = document.querySelectorAll('dialog').length

    const wrapper = mount(Modal, {
        props: { show: false, ...props },
        slots: { default: '<p>Contenu</p>' },
        attachTo: document.body,
    })

    wrapper.dialog = document.querySelectorAll('dialog')[before]

    return wrapper
}

const isPageScrollable = () => document.documentElement.style.overflow !== 'hidden'

beforeEach(() => {
    vi.useFakeTimers()
    document.documentElement.style.removeProperty('overflow')
})

afterEach(() => {
    vi.useRealTimers()
})

/**
 * The modal locks the page behind it by writing `overflow: hidden` onto <html>,
 * the element the viewport reads its overflow from, and gives it back when it
 * shuts. Every path out of the open state has to give it back — a lock left
 * standing costs the user the whole app: nothing on screen, and no scrolling
 * anywhere until the page is reloaded.
 */
describe('Modal page scroll lock', () => {
    it('locks the page while open and gives it back when shut', async () => {
        const wrapper = mountModal({ show: false })

        expect(isPageScrollable()).toBe(true)

        await wrapper.setProps({ show: true })
        expect(isPageScrollable()).toBe(false)

        await wrapper.setProps({ show: false })
        expect(isPageScrollable()).toBe(true)

        wrapper.unmount()
    })

    /**
     * Escape closes a native dialog on its own: the browser fires the close
     * event, no code of ours runs first. Nothing was listening, so the dialog
     * went away and the lock stayed — an app that cannot scroll, with no modal
     * on screen to explain why.
     */
    it('gives the page back when the browser closes the dialog itself', async () => {
        const wrapper = mountModal({ show: true })
        await wrapper.vm.$nextTick()

        wrapper.dialog.close()
        await wrapper.vm.$nextTick()

        expect(isPageScrollable()).toBe(true)
        expect(wrapper.emitted('close')).toHaveLength(1)

        wrapper.unmount()
    })

    /**
     * Shutting schedules the dialog's close 200ms out, to let the panel fade.
     * Re-opening inside that window — confirming one deletion and immediately
     * asking for the next — left the stale timeout to close the dialog that had
     * just re-opened: locked page, empty screen. Reachable in two taps on the
     * workout page.
     */
    it('survives being re-opened inside the closing animation', async () => {
        const wrapper = mountModal({ show: true })
        await wrapper.vm.$nextTick()

        await wrapper.setProps({ show: false })
        vi.advanceTimersByTime(50)
        await wrapper.setProps({ show: true })

        vi.advanceTimersByTime(500)
        await wrapper.vm.$nextTick()

        expect(wrapper.dialog.open).toBe(true)
        expect(wrapper.dialog.textContent).toContain('Contenu')

        await wrapper.setProps({ show: false })
        vi.advanceTimersByTime(500)
        expect(isPageScrollable()).toBe(true)

        wrapper.unmount()
    })

    /**
     * The lock was a single global flag with no notion of who held it, so any
     * modal shutting released it for all of them — including one still on
     * screen, whose background then scrolled underneath it.
     */
    it('keeps the page locked while another modal is still open', async () => {
        const first = mountModal({ show: true })
        const second = mountModal({ show: true })
        await second.vm.$nextTick()

        await second.setProps({ show: false })
        vi.advanceTimersByTime(500)

        expect(isPageScrollable()).toBe(false)

        await first.setProps({ show: false })
        vi.advanceTimersByTime(500)

        expect(isPageScrollable()).toBe(true)

        first.unmount()
        second.unmount()
    })

    /** Unmounting a modal that never opened must not free a lock it never took. */
    it('does not release a lock it never took when it unmounts', async () => {
        const open = mountModal({ show: true })
        const neverOpened = mountModal({ show: false })
        await open.vm.$nextTick()

        neverOpened.unmount()

        expect(isPageScrollable()).toBe(false)

        open.unmount()
        expect(isPageScrollable()).toBe(true)
    })

    /** Leaving the page with a modal open has to give the scroll back too. */
    it('gives the page back when it unmounts while open', async () => {
        const wrapper = mountModal({ show: true })
        await wrapper.vm.$nextTick()

        wrapper.unmount()

        expect(isPageScrollable()).toBe(true)
    })
})
