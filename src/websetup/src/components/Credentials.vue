<template>
    <form class="pt-6 w-50 mx-auto">
        <legend class="text-h4 mb-6">Login credentials</legend>

        <v-select class="me-10" :error-messages="dimension.errorMessage.value" label="Dimension" :items="dimensions"
            item-title="name" item-value="value" v-model="dimension.value.value"></v-select>

        <v-text-field v-model="login.value.value" class="me-10" :error-messages="login.errorMessage.value"
            label="Username"></v-text-field>

        <v-text-field v-model="password.value.value" :append-icon="showPassword ? 'mdi-eye' : 'mdi-eye-off'"
            :type="showPassword ? 'text' : 'password'" class="mb-4" label="Password" counter
            :error-messages="password.errorMessage.value" @click:append="showPassword = !showPassword"></v-text-field>
    </form>
</template>

<script lang="ts" setup>
import { useField, useForm, type FieldContext } from 'vee-validate'
import { ref } from 'vue';

const dimensions = ref([
    {
        name: 'RK5',
        value: 5
    },
    {
        name: 'RK19',
        value: 6,
    }
])

const { validate, values } = useForm({
    validationSchema: {
        login(value: string | null) {
            if (value != null && 6 <= value.length && value.length <= 15) return true

            return 'Username must be between 6 and 15 characters'
        },
        password(value: string | null) {
            if (value != null && value.match(/^[a-zA-Z0-9-]{6,20}$/) != null) return true

            if (value != null && value.length < 6)
                return 'Password must be at least 6 characters'
            else if (value != null && value.length > 20)
                return 'Password must not exceed 20 characters'
            else
                return 'Password contains invalid characters'
        }
    },
})

const dimension: FieldContext<Number> = useField('dimension', {}, { initialValue: 5 })
const login: FieldContext<String> = useField('login')
const password: FieldContext<String> = useField('password')

const showPassword = ref(false);

defineExpose({ validate, values })
</script>