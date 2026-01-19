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
