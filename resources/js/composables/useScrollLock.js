/**
 * useScrollLock - page scroll lock shared by every overlay
 *
 * An overlay that covers the screen has to stop the page scrolling underneath
 * it. `overflow: hidden` is how that is done — it stops the user without
 * stopping the program, so the scroll position survives.
 *
 * It goes on <html>. The viewport takes its overflow from the root element,
 * and only falls back to <body> when the root's own overflow is `visible`.
 * Ours is not — app.css clips the root horizontally — so the lock was being
 * written to an element the viewport never consulted, and the page went on
 * scrolling behind every modal.
 *
 * The property is also a single global with no notion of who set it: the last
 * overlay to touch it wins, whatever the others still need.
 *
 * That cost users the whole app. Two modals, the second one shuts, the lock is
 * released while the first is still on screen — background scrolling behind an
 * open dialog. And the reverse, which is worse: any path that hides an overlay
 * without running its release — the browser closing a <dialog> by itself, a
 * stale timer, an exception — leaves `overflow: hidden` standing with nothing
 * on screen to explain it. The app simply stops scrolling until it is reloaded.
 *
 * So the lock is held by name. Each holder passes a token, releasing is scoped
 * to that token, and the page is only given back once the last one lets go.
 * Both calls are idempotent: locking twice holds one lock, releasing something
 * that never held it does nothing.
 */

/** @type {Set<symbol>} Tokens currently holding the page. */
const holders = new Set()

/**
 * Claim the page scroll on behalf of one overlay.
 *
 * @param {symbol} token - Identifies the holder; the same token must release it.
 */
export function lockPageScroll(token) {
    holders.add(token)
    document.documentElement.style.setProperty('overflow', 'hidden')
}

/**
 * Let go of one holder's claim, giving the page back once none are left.
 *
 * @param {symbol} token - The token passed to lockPageScroll.
 */
export function releasePageScroll(token) {
    if (!holders.delete(token)) {
        return
    }

    if (holders.size === 0) {
        // Removed rather than set to a value, so the stylesheet's own overflow
        // rules come back rather than being permanently overridden.
        document.documentElement.style.removeProperty('overflow')
    }
}

/**
 * A token to hold the lock with. One per overlay instance.
 *
 * @param {string} [name] - Shown when inspecting the symbol; debugging only.
 * @return {symbol}
 */
export function createScrollLockToken(name = 'overlay') {
    return Symbol(`scroll-lock:${name}`)
}
