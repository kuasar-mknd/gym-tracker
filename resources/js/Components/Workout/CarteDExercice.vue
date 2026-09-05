<script setup>
/**
 * Un exercice de la seance : son en-tete, sa poignee de deplacement, ses
 * series et le bouton qui en ajoute une. Les ecritures restent a la page ;
 * la carte relaie ce que la rangee lui dit, en nommant la serie.
 */
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import RangeeDeSerie from '@/Components/Workout/RangeeDeSerie.vue'

defineProps({
    line: { type: Object, required: true },
    lineIndex: { type: Number, required: true },
    isFinished: { type: Boolean, default: false },
    deplacable: { type: Boolean, default: false },
    reordonnable: { type: Boolean, default: false },
    serieEnVol: { type: Boolean, default: false },
    generation: { type: Number, default: 0 },
    clef: { type: Function, required: true },
    estNonSynchronisee: { type: Function, required: true },
    poserLeConteneur: { type: Function, default: undefined },
})

defineEmits([
    'deplacer',
    'retirer',
    'ajouter-serie',
    'toggle',
    'remove',
    'saisie-en-cours',
    'saisie-terminee',
    'update',
    'deplacer-serie',
    'pointerdown',
])
</script>

<template>
    <GlassCard
        :dusk="`exercise-card-${lineIndex}`"
        :data-line-id="line.id"
        :dusk-id="`exercise-line-${line.id}`"
        data-exercice
        class="carte-portable"
    >
        <div class="mb-4 flex items-center justify-between gap-2">
            <div class="min-w-0">
                <!--
              text-text-main is near-black and has no dark variant of
              its own, so in dark mode the exercise name was rendered
              at 2.20:1 against the page and its category at 1.71:1 —
              both far under the 4.5:1 that ordinary text needs, and
              under even the 3:1 allowed for large text. The name of
              the exercise you are working on was effectively
              invisible.
            -->
                <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                    {{ line.exercise.name }}
                </h3>
                <p class="text-text-muted text-xs font-bold uppercase">
                    {{ line.exercise.category }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <!--
              Une poignee, et non la carte entiere : les rangees de
              series sont deja sensibles au glissement lateral, et
              laisser la bibliotheque ecouter toute la carte les
              rendrait inutilisables au doigt.
            -->
                <button
                    v-if="!isFinished && deplacable"
                    type="button"
                    data-poignee-exercice
                    class="text-text-muted focus-visible:ring-accent-primary min-h-touch min-w-touch inline-flex cursor-grab touch-none items-center justify-center rounded-lg transition-colors select-none [-webkit-touch-callout:none] focus-visible:ring-2 focus-visible:outline-none active:cursor-grabbing"
                    :dusk="`reorder-line-${lineIndex}`"
                    :aria-label="`Déplacer ${line.exercise.name}`"
                    @keydown.up.prevent="$emit('deplacer', lineIndex - 1)"
                    @keydown.down.prevent="$emit('deplacer', lineIndex + 1)"
                >
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">drag_indicator</span>
                </button>

                <GlassIconButton
                    v-press="{ haptic: 'warning' }"
                    icon="delete"
                    label="Supprimer l'exercice"
                    ton="danger"
                    compact
                    :dusk="`remove-line-${lineIndex}`"
                    @click="$emit('retirer')"
                />
            </div>
        </div>

        <div :ref="poserLeConteneur" class="space-y-2">
            <!--
          Keyed on something that never changes for the life of the
          row. Folding the index in made the key change for every row
          below a deletion, so Vue destroyed and rebuilt them all:
          swipe state reset, inputs re-created, and a field being
          edited losing focus mid-keystroke.

          The set's id has the same defect and outlived that fix: an
          optimistic row wears a placeholder until the server answers,
          and `tempSet.id = realSetId` then changes the key, so Vue
          tears the row down and builds a new one — swipe state and
          inputs included — at the moment the user is most likely to
          be filling it in.

          Demonstrated, and no more than that: the guard in
          workoutSetEntry.test.js fails without this, showing the node
          really is replaced. It was written while chasing a lost
          duration entry and did NOT fix it, so nothing here should be
          read as a diagnosis of that.
        -->
            <RangeeDeSerie
                v-for="(set, index) in line.sets"
                :key="`${clef(set)}:${generation}`"
                :set="set"
                :index="index"
                :line-index="lineIndex"
                :line="line"
                :is-finished="isFinished"
                :unsynced="estNonSynchronisee(set)"
                :reordonnable="reordonnable"
                :disabled="serieEnVol"
                @pointerdown="$emit('pointerdown', $event)"
                @toggle="$emit('toggle', set)"
                @remove="$emit('remove', set)"
                @saisie-en-cours="(field, value) => $emit('saisie-en-cours', set, field, value)"
                @saisie-terminee="(field, value) => $emit('saisie-terminee', set, field, value)"
                @update="(field, value) => $emit('update', set, field, value)"
                @deplacer="(nouveau) => $emit('deplacer-serie', index, nouveau)"
            />
        </div>

        <button
            v-if="!isFinished"
            v-press
            @click="$emit('ajouter-serie')"
            :dusk="`add-set-${lineIndex}`"
            class="text-text-muted hover:border-accent-state border-border mt-4 flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed py-3 text-sm font-bold uppercase transition-all"
        >
            Ajouter une série
        </button>
    </GlassCard>
</template>
