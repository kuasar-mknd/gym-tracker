<script setup>
defineProps({
    variant: {
        type: String,
        default: 'default', // default | primary | neon | gradient-border | danger | ghost
    },
    size: {
        type: String,
        default: 'md', // sm | md | lg | xl
    },
    loading: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: 'button',
    },
    icon: {
        type: String,
        default: null,
    },
})

const sizeClasses = {
    sm: 'min-h-[36px] px-4 py-2 text-sm rounded-xl',
    md: 'min-h-[44px] px-5 py-2.5 text-base rounded-xl',
    lg: 'min-h-[52px] px-6 py-3 text-lg rounded-2xl',
    xl: 'min-h-[64px] px-8 py-4 text-xl rounded-2xl',
}
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'glass-button transition-all active:scale-95',
            sizeClasses[size],
            {
                'glass-button-primary shadow-glow-orange': variant === 'primary',
                'glass-button-neon shadow-neon': variant === 'neon',
                'glass-button-gradient-border': variant === 'gradient-border',
                'border-red-500/30 bg-red-500/10 text-red-600 hover:bg-red-500/20': variant === 'danger',
                'border-transparent bg-transparent shadow-none hover:bg-white/50': variant === 'ghost',
                'cursor-not-allowed opacity-50': disabled,
                'cursor-wait': loading,
            },
        ]"
    >
        <!-- Loading Spinner -->
        <svg
            v-if="loading"
            class="mr-2 h-5 w-5 animate-spin"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>

        <!-- Icon (Material Symbols) -->
        <span
            v-if="icon && !loading"
            class="material-symbols-outlined mr-1 text-current"
            :style="{ fontSize: size === 'sm' ? '18px' : size === 'lg' ? '28px' : '24px' }"
        >
            {{ icon }}
        </span>

        <slot />
    </button>
</template>
