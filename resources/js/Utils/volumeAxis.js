/**
 * Labels a volume axis tick, shortening kilogrammes past a thousand.
 *
 * `toFixed(1)`, not `toFixed(0)`. Rounding to the thousand before appending the
 * "k" crushes every finer scale: with the 500 kg step Chart.js picks for
 * smaller volumes, the tick at 1500 was labelled "2k" and the axis read
 * "1k, 2k, 2k, 3k, 3k" — duplicated labels, each 500 kg out. MonthlyVolumeChart
 * shipped that version while VolumeTrendChart, holding a copy of the same
 * expression, did not. Two copies of one rule is how one of them goes wrong;
 * this is the single copy.
 *
 * Values under a thousand come back untouched — as the number Chart.js handed
 * over, not a string — so the axis keeps its own formatting at the small end.
 *
 * @param {number} value - The tick value, in kilogrammes.
 * @returns {string|number} "1.5k" from a thousand up, the value itself below it.
 */
export function formatVolumeTick(value) {
    if (value >= 1000) {
        return (value / 1000).toFixed(1) + 'k'
    }

    return value
}
