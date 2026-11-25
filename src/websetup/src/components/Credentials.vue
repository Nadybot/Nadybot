<template>
  <form class="pt-6 w-50 mx-auto">
    <legend class="text-h4 mb-6">Login credentials</legend>

    <v-select
      :error-messages="dimension.errorMessage.value"
      label="Dimension"
      :items="dimensions"
      item-title="name"
      item-value="value"
      v-model="dimension.value.value"
    ></v-select>

    <v-text-field
      v-model="login.value.value"
      :error-messages="login.errorMessage.value"
      label="Username"
    ></v-text-field>

    <v-text-field
      v-model="password.value.value"
      :append-icon="showPassword ? '$eye' : '$eyeOff'"
      :type="showPassword ? 'text' : 'password'"
      class="mb-4"
      label="Password"
      counter
      :error-messages="password.errorMessage.value"
      @click:append="showPassword = !showPassword"
    ></v-text-field>
  </form>
</template>

<script lang="ts" setup>
import { useField, useForm, type FieldContext } from 'vee-validate'
import { ref } from 'vue'

const LOST_EDEN = 7

const dimensions = ref([
  {
    name: 'RK5',
    value: 5,
  },
  {
    name: 'RK19',
    value: 6,
  },
  {
    name: 'Lost Eden',
    value: 7,
  },
])

const { validate, values } = useForm({
  validationSchema: {
    login(value: string | null) {
      if (value != null && 6 <= value.length && value.length <= 15) return true

      return 'Username must be between 6 and 15 characters'
    },
    password(value: string | null, ctx: { form: Record<string, unknown> }) {
      // Funcom limits passwords to 20 letters, digits and dashes, Lost Eden doesn't
      const isFuncom = ctx.form.dimension !== LOST_EDEN

      if (value == null || value.length < 6) return 'Password must be at least 6 characters'
      if (isFuncom && value.length > 20) return 'Password must not exceed 20 characters'
      if (isFuncom && value.match(/^[a-zA-Z0-9-]+$/) == null) return 'Password contains invalid characters'

      return true
    },
  },
})

const dimension: FieldContext<number> = useField('dimension', {}, { initialValue: 5 })
const login: FieldContext<string> = useField('login')
const password: FieldContext<string> = useField('password')

const showPassword = ref(false)

defineExpose({ validate, values })
</script>
