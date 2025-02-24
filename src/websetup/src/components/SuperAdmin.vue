<template>
    <form class="pt-6 w-50 mx-auto">
        <legend class="text-h4 mb-6">Superadmin</legend>

        <v-text-field v-model="superadmin.value.value" class="me-10" :error-messages="superadmin.errorMessage.value"
            label="Superadmin"></v-text-field>
    </form>
</template>

<script lang="ts" setup>
import { useField, useForm, type FieldContext } from 'vee-validate'

const { validate, values } = useForm({
    validationSchema: {
        superadmin(value: string | null) {
            if (value != null && value.match(/^[A-Z][a-z0-9]{3,}(-?\d)?$/) != null && value.length >= 4 && value.length <= 12) return true

            if (value != null && value.length < 4)
                return 'Character name must be at least 4 characters'
            else if (value != null && value.length > 12)
                return 'Character name must not exceed 12 characters'
            else
                return 'Name is not a valid character name'
        }
    }
})

const superadmin: FieldContext<String> = useField('superadmin')

defineExpose({ validate, values })
</script>