/**
 * The wrappers every page test has to get out of the way.
 *
 * A page is mounted for the data it computes and the copy it renders, not for
 * the chrome around it. The layout stub forwards the three slots the pages
 * actually fill — a stub that forwards only the default slot silently drops
 * whatever a page puts in `#header` or `#header-actions`, and an assertion
 * about a missing header button then passes for the wrong reason.
 */
export const passesSlot = { template: '<div><slot /></div>' }

export const layoutStub = {
    template: '<div><slot name="header-actions" /><slot name="header" /><slot /></div>',
}
