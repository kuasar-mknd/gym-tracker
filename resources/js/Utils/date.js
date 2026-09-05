/**
 * Reads a calendar day (`YYYY-MM-DD`) as local midnight.
 *
 * `new Date('2026-07-31')` is specified to parse as midnight *UTC*, while
 * `new Date('2026-07-31T00:00:00')` parses as midnight local. Rendered with
 * toLocaleDateString, the first form shows the day before everywhere the
 * browser is behind UTC — the whole American continent, and London in winter.
 *
 * A day the user typed is not an instant. It must read back as the same day
 * wherever it is read.
 *
 * @param {string} value - A calendar day, or an ISO timestamp to take the day from.
 * @returns {Date|null} Local midnight on that day, or null if unparseable.
 */
export function parseCalendarDate(value) {
    if (!value) return null

    const day = String(value).substring(0, 10)
    const parsed = new Date(`${day}T00:00:00`)

    return isNaN(parsed.getTime()) ? null : parsed
}

/**
 * Today as a calendar day (`YYYY-MM-DD`), read in the browser's timezone.
 *
 * `new Date().toISOString().substring(0, 10)` gives the day in UTC, which is
 * still yesterday during the first hours of every local day anywhere ahead of
 * UTC — 00:00 to 02:00 in Paris in summer. Forms seeded that way offered
 * yesterday's date, and the journal upserts on the date, so saving overwrote
 * the previous day's entry instead of creating today's.
 *
 * @returns {string} Today, as YYYY-MM-DD.
 */
export function todayAsCalendarDate() {
    const now = new Date()
    const month = String(now.getMonth() + 1).padStart(2, '0')
    const day = String(now.getDate()).padStart(2, '0')

    return `${now.getFullYear()}-${month}-${day}`
}

/**
 * Converts a UTC date string or Date object to a local ISO string (YYYY-MM-DDTHH:mm).
 * This is suitable for <input type="datetime-local">.
 *
 * @param {string|Date} date - The date to convert.
 * @returns {string} The local ISO string.
 */
export function formatToLocalISO(date) {
    if (!date) return ''
    const d = new Date(date)
    if (isNaN(d.getTime())) return ''

    // Offsetting the UTC date by the local timezone offset
    // so that toISOString() returns the "local" time as the lead string
    const offset = d.getTimezoneOffset() * 60000
    const localDate = new Date(d.getTime() - offset)

    return localDate.toISOString().slice(0, 16)
}

/**
 * Converts a local date string (from <input type="datetime-local">) back to a UTC ISO string.
 * This is suitable for sending to a server that expects UTC.
 *
 * @param {string} localDateString - The local date string (YYYY-MM-DDTHH:mm).
 * @returns {string} The UTC ISO string.
 */
export function formatToUTC(localDateString) {
    if (!localDateString) return null
    const d = new Date(localDateString)
    if (isNaN(d.getTime())) return null

    return d.toISOString()
}

/**
 * L'étiquette d'une date sur un axe : `jj/mm`, comme le serveur les écrit.
 *
 * Quatre formats se côtoyaient d'une carte à l'autre (« 20/07 », « 20 juil. »,
 * « lun. 20 juil. », « 05/09/2026 ») ; un seul, court, que le serveur produit
 * déjà pour les séries denses.
 *
 * @param {Date|string|number} valeur une Date, une date calendaire `AAAA-MM-JJ` ou un horodatage
 * @returns {string}
 */
export function etiquetteDeDate(valeur) {
    if (valeur === null || valeur === undefined || valeur === '') {
        return undefined
    }

    const date =
        valeur instanceof Date
            ? valeur
            : typeof valeur === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(valeur)
              ? parseCalendarDate(valeur)
              : new Date(valeur)

    return date && !Number.isNaN(date.getTime())
        ? date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
        : undefined
}
