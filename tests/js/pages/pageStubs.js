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

/**
 * La mise en page sous 1 024 px de large, où la barre de bureau ne s'ouvre pas
 * (variante `bureau:` de app.css, #2057) : l'en-tête mobile, ses actions et la
 * page, sans l'en-tête de page (`#header`, que le layout cache jusqu'à
 * `bureau:`). Ce qu'une page ne montre que dans `#header` n'existe pas sur une
 * tablette debout ni sur un téléphone.
 */
export const layoutSansEnTeteDePage = {
    template: '<div><slot name="header-actions" /><slot /></div>',
}
