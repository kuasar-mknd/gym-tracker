/**
 * vue-chartjs replaced rather than stubbed by name: the real components reach
 * for a canvas jsdom never draws, and what these suites need is the data and
 * the options handed to the chart — which is exactly where the series, the
 * labels and the tooltip formatting live.
 *
 * chartAxes.test.js declares the same mock inline; this module exists so the
 * suites written after it do not each keep their own copy of it.
 *
 * @returns {Object} The four chart components vue-chartjs exports here.
 */
export const chartRecorders = () => {
    const recorder = (name) => ({
        name,
        props: {
            data: { type: Object, default: null },
            options: { type: Object, default: null },
            plugins: { type: Array, default: () => [] },
        },
        template: '<div />',
    })

    return {
        Bar: recorder('Bar'),
        Line: recorder('Line'),
        Doughnut: recorder('Doughnut'),
        Scatter: recorder('Scatter'),
    }
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper
 * @param {string} type - 'Bar', 'Line' or 'Doughnut'.
 * @returns {{labels: Array, datasets: Array}} What Chart.js would have drawn.
 */
export const chartDataOf = (wrapper, type) => wrapper.findComponent({ name: type }).props('data')

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper
 * @param {string} type - 'Bar', 'Line' or 'Doughnut'.
 * @returns {Object} The options object, tooltip callbacks included.
 */
export const chartOptionsOf = (wrapper, type) => wrapper.findComponent({ name: type }).props('options')

/**
 * Runs the tooltip label callback the way Chart.js would, on a hovered point.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper
 * @param {string} type - 'Bar', 'Line' or 'Doughnut'.
 * @param {Object} context - The Chart.js tooltip context to feed it.
 * @returns {string} The line the user reads in the tooltip.
 */
export const tooltipLabelOf = (wrapper, type, context) =>
    chartOptionsOf(wrapper, type).plugins.tooltip.callbacks.label(context)
