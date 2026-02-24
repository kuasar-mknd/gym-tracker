import os

file_path = 'resources/js/Components/Workout/RestTimer.vue'
with open(file_path, 'r') as f:
    lines = f.readlines()

# Add state
state_insertion_index = -1
for i, line in enumerate(lines):
    if 'const emit = defineEmits' in line:
        state_insertion_index = i + 1
        break

if state_insertion_index != -1:
    new_state = [
        '\n',
        '// --- Drag State ---\n',
        'const offset = ref({ x: 0, y: 0 })\n',
        'const isDragging = ref(false)\n',
        'const dragStart = { x: 0, y: 0 }\n',
        '\n',
        'const handlePointerDown = (e) => {\n',
        '    if (e.target.closest("button")) return\n',
        '    isDragging.value = true\n',
        '    dragStart.x = e.clientX - offset.value.x\n',
        '    dragStart.y = e.clientY - offset.value.y\n',
        '    e.currentTarget.setPointerCapture(e.pointerId)\n',
        '}\n',
        '\n',
        'const handlePointerMove = (e) => {\n',
        '    if (!isDragging.value) return\n',
        '    offset.value.x = e.clientX - dragStart.x\n',
        '    offset.value.y = e.clientY - dragStart.y\n',
        '}\n',
        '\n',
        'const handlePointerUp = (e) => {\n',
        '    if (!isDragging.value) return\n',
        '    isDragging.value = false\n',
        '    e.currentTarget.releasePointerCapture(e.pointerId)\n',
        '}\n'
    ]
    lines[state_insertion_index:state_insertion_index] = new_state

# Update Template
content = "".join(lines)
search_text = '''        <!-- Liquid Glass Card -->
        <div
            class="relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 shadow-2xl backdrop-blur-md transition-all duration-300 dark:bg-black/40"
        >'''

replace_text = '''        <!-- Liquid Glass Card -->
        <div
            @pointerdown="handlePointerDown"
            @pointermove="handlePointerMove"
            @pointerup="handlePointerUp"
            :class="[isDragging ? 'cursor-grabbing' : 'cursor-grab', 'relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 shadow-2xl backdrop-blur-md dark:bg-black/40']"
            :style="{
                transform: `translate(${offset.x}px, ${offset.y}px)`,
                transition: isDragging ? 'none' : 'transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), background-color 0.3s, border-color 0.3s, box-shadow 0.3s'
            }"
        >'''

if search_text in content:
    new_content = content.replace(search_text, replace_text)
    with open(file_path, 'w') as f:
        f.write(new_content)
    print("Replacement successful")
else:
    print("Search text not found")
