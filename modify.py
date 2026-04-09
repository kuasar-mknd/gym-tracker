import re

with open('resources/js/Components/Stats/VolumePerWorkoutChart.vue', 'r') as f:
    content = f.read()

# Add import
import_stmt = "import { computed } from 'vue'\nimport { commonTooltipOptions, volumeTooltipCallback } from './chartConfig'\n"
content = content.replace("import { computed } from 'vue'\n", import_stmt)

# Replace tooltip config
old_tooltip = """        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            padding: 12,
            cornerRadius: 12,
            borderWidth: 0,
            callbacks: {
                label: function (context) {
                    let label = context.dataset.label || ''
                    if (label) {
                        label += ': '
                    }
                    if (context.parsed.y !== null) {
                        label += context.parsed.y.toLocaleString() + ' kg'
                    }
                    return label
                },
            },
        },"""

new_tooltip = """        tooltip: {
            ...commonTooltipOptions,
            callbacks: {
                label: volumeTooltipCallback,
            },
        },"""

content = content.replace(old_tooltip, new_tooltip)

with open('resources/js/Components/Stats/VolumePerWorkoutChart.vue', 'w') as f:
    f.write(content)
