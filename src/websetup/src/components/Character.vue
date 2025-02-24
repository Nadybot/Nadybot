<template>
    <form class="pt-6 w-50 mx-auto">
        <legend class="text-h4 mb-6">Select a character</legend>

        <v-select class="me-10" :error-messages="character.errorMessage.value" label="Character"
            :items="props.characters" item-title="display" item-value="value"
            v-model="character.value.value"></v-select>
    </form>
</template>

<script lang="ts" setup>
import { useField, useForm, type FieldContext } from 'vee-validate'

const { validate, values } = useForm({
    validationSchema: {
        character(value: string | null) {
            if (value != null) return true

            return 'Please select a character'
        }
    }
})

const character: FieldContext<String> = useField('character')

export interface CharacterOption {
    display: String,
    value: String,
}

const props = defineProps({ characters: { type: Array<CharacterOption>, required: true } })
defineExpose({ validate, values })
</script>