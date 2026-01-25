<script setup>
import { useForm } from '@inertiajs/vue3'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { ref } from 'vue'

const props = defineProps({
    injury: {
        type: Object,
        default: null,
    },
})

const emit = defineEmits(['close', 'success'])

const form = useForm({
    body_part: props.injury?.body_part || '',
    diagnosis: props.injury?.diagnosis || '',
    severity: props.injury?.severity || 'low',
    status: props.injury?.status || 'active',
    pain_level: props.injury?.pain_level || 5,
    occurred_at: props.injury?.occurred_at || new Date().toISOString().split('T')[0],
    healed_at: props.injury?.healed_at || '',
    notes: props.injury?.notes || '',
})

const submit = () => {
    if (props.injury) {
        form.put(route('injuries.update', props.injury.id), {
            onSuccess: () => emit('success'),
        })
    } else {
        form.post(route('injuries.store'), {
            onSuccess: () => emit('success'),
        })
    }
}
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <GlassInput
            v-model="form.body_part"
            label="Body Part"
            placeholder="e.g. Right Knee"
            :error="form.errors.body_part"
            required
        />

        <GlassInput
            v-model="form.diagnosis"
            label="Diagnosis (Optional)"
            placeholder="e.g. ACL Sprain"
            :error="form.errors.diagnosis"
        />

        <div class="grid grid-cols-2 gap-4">
             <div class="space-y-2">
                <label class="font-display-label text-text-muted mb-2 block">Severity</label>
                <select
                    v-model="form.severity"
                    class="glass-input w-full"
                >
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                </select>
                <p v-if="form.errors.severity" class="text-sm text-red-500">{{ form.errors.severity }}</p>
            </div>

            <div class="space-y-2">
                <label class="font-display-label text-text-muted mb-2 block">Status</label>
                <select
                    v-model="form.status"
                    class="glass-input w-full"
                >
                    <option value="active">Active</option>
                    <option value="recovering">Recovering</option>
                    <option value="healed">Healed</option>
                </select>
                 <p v-if="form.errors.status" class="text-sm text-red-500">{{ form.errors.status }}</p>
            </div>
        </div>

        <GlassInput
            v-model="form.pain_level"
            type="number"
            min="1"
            max="10"
            label="Pain Level (1-10)"
            :error="form.errors.pain_level"
        />

        <GlassInput
            v-model="form.occurred_at"
            type="date"
            label="Occurred At"
            :error="form.errors.occurred_at"
            required
        />

        <GlassInput
            v-if="form.status === 'healed'"
            v-model="form.healed_at"
            type="date"
            label="Healed At"
            :error="form.errors.healed_at"
        />

        <GlassInput
            v-model="form.notes"
            label="Notes"
            placeholder="Additional details..."
            :error="form.errors.notes"
        />

        <div class="flex justify-end gap-3">
            <GlassButton type="button" variant="secondary" @click="$emit('close')">
                Cancel
            </GlassButton>
            <GlassButton type="submit" :loading="form.processing">
                {{ props.injury ? 'Update' : 'Log Injury' }}
            </GlassButton>
        </div>
    </form>
</template>
