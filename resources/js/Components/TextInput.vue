<script setup>
import { onMounted, ref } from 'vue'

const model = defineModel({
    type: String,
    required: true,
})

const input = ref(null)

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus()
    }
})

defineExpose({ focus: () => input.value.focus() })
</script>

<template>
    <input
        class="w-full rounded-3xl border border-white/20 bg-white/10 px-4 py-3 text-white placeholder-white/50 backdrop-blur-md transition-all duration-300 hover:border-white/30 hover:bg-white/15 focus:border-white/50 focus:bg-white/20 focus:shadow-[0_0_15px_rgba(255,255,255,0.1)] focus:ring-0 focus:outline-none active:scale-[0.98]"
        v-model="model"
        ref="input"
    />
</template>
