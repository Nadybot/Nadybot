<template>
    <form class="pt-6 w-50 mx-auto">
        <legend class="text-h4 mb-6">Configure the database</legend>

        <v-select class="me-10" :error-messages="type.errorMessage.value" label="Type" :items="props.databases"
            v-model="type.value.value" :disabled="props.databases.length == 1"
            v-on:update:model-value="changeDefaults"></v-select>

        <template v-if="type.value.value == 'sqlite'">
            <v-text-field v-model="host.value.value" class="me-10" :error-messages="host.errorMessage.value"
                label="Database file directory"></v-text-field>

            <v-text-field v-model="name.value.value" class="me-10" :error-messages="name.errorMessage.value"
                label="Database file name"></v-text-field>
        </template>
        <template v-else-if="type.value.value != undefined">
            <v-text-field v-model="host.value.value" class="me-10" :error-messages="host.errorMessage.value"
                label="Database host"></v-text-field>

            <v-text-field v-model="name.value.value" class="me-10" :error-messages="name.errorMessage.value"
                label="Database name"></v-text-field>

            <v-text-field v-model="username.value.value" class="me-10" :error-messages="username.errorMessage.value"
                label="Database username"></v-text-field>

            <v-text-field v-model="password.value.value" class="me-10" :error-messages="password.errorMessage.value"
                label="Database password"></v-text-field>
        </template>
    </form>
</template>

<script lang="ts" setup>
import { useField, useForm, type FieldContext } from 'vee-validate'

const { validate, values } = useForm({
    validationSchema: {
    }
})

const changeDefaults = () => {
    if (type.value.value == 'sqlite') {
        host.value.value = './data/'
        name.value.value = 'nadybot.db'
    } else {
        host.value.value = ''
        name.value.value = 'nadybot'
    }
}

const props = defineProps({ databases: { type: Array<String>, required: true } })
const type: FieldContext<String | undefined> = useField('type', {}, { initialValue: props.databases.length == 1 ? props.databases[0] : undefined })
const host: FieldContext<String> = useField('host', {})
const name: FieldContext<String> = useField('name')
const password: FieldContext<String> = useField('password')
const username: FieldContext<String> = useField('username')

defineExpose({ validate, values })
</script>