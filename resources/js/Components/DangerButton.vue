<template>
    <button
        class="inline-flex items-center justify-center rounded-2xl border border-red-500/30 bg-red-500/20 px-4 py-2 text-xs font-black tracking-widest text-red-100 uppercase backdrop-blur-md transition-all duration-300 hover:scale-105 hover:bg-red-500/40 hover:shadow-[0_0_15px_rgba(239,68,68,0.4)] focus:ring-2 focus:ring-red-500/50 focus:ring-offset-2 focus:ring-offset-transparent focus:outline-none active:scale-95 disabled:cursor-not-allowed disabled:opacity-25"
    >
        <slot />
    </button>
</template>
