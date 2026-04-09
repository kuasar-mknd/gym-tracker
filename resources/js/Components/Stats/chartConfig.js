export const commonTooltipOptions = {
    backgroundColor: 'rgba(255, 255, 255, 0.9)',
    titleColor: '#1e293b',
    bodyColor: '#1e293b',
    padding: 12,
    cornerRadius: 12,
    borderWidth: 0,
}

export const volumeTooltipCallback = function (context) {
    let label = context.dataset.label || ''
    if (label) {
        label += ': '
    }
    if (context.parsed.y !== null) {
        label += context.parsed.y.toLocaleString() + ' kg'
    }
    return label
}
