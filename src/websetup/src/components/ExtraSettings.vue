<template>
    <form class="pt-6 w-50 mx-auto">
        <legend class="text-h4 mb-6">Extra settings</legend>

        <v-switch class="me-10" color="primary" :error-messages="webui.errorMessage.value" label="Enable WebUI"
            v-model="webui.value.value"></v-switch>

        <template v-if="webui.value.value">
            <v-text-field v-model="host.value.value" class="me-10" :error-messages="host.errorMessage.value"
                label="Listen address"></v-text-field>

            <v-text-field v-model="port.value.value" class="me-10" :error-messages="port.errorMessage.value"
                label="Listen port"></v-text-field>

            <v-select class="me-10" :error-messages="authMethod.errorMessage.value" label="Authentication method"
                :items="authTypes" item-title="name" item-value="value" v-model="authMethod.value.value"></v-select>

            <v-select class="me-10" :error-messages="drill.errorMessage.value" label="Drill server" :items="drillTypes"
                item-title="name" item-value="value" v-model="drill.value.value"></v-select>
        </template>

        <v-switch class="me-10" color="primary" :error-messages="enableAllModules.errorMessage.value"
            label="Enable all modules by default" v-model="enableAllModules.value.value"></v-switch>

        <v-switch class="me-10" color="primary" :error-messages="enablePackageManager.errorMessage.value"
            label="Enable the package manager" v-model="enablePackageManager.value.value"></v-switch>

        <v-switch class="me-10" color="primary" :error-messages="orgbot.errorMessage.value"
            label="Enable org-bot functionality" v-model="orgbot.value.value"></v-switch>
    </form>
</template>

<script lang="ts" setup>
import { useField, useForm, type FieldContext } from 'vee-validate'
import { ref } from 'vue'

const { validate, values } = useForm({
    validationSchema: {
    }
})

const authTypes = ref([
    {
        name: 'AoAuth',
        value: 'aoauth'
    },
    {
        name: 'Token',
        value: 'webauth',
    }
])

const drillTypes = ref([
    {
        name: 'Off',
        value: 'off'
    },
    {
        name: 'US-based',
        value: 'wss://drill.us.nadybot.org'
    },
    {
        name: 'EU-based',
        value: 'wss://drill.nadybot.org'
    }
])

const webui: FieldContext<Boolean> = useField('webui', {}, { initialValue: true })
const host: FieldContext<String> = useField('host', {}, { initialValue: "127.0.0.1" })
const port: FieldContext<String> = useField('port', {}, { initialValue: "8080" })
const authMethod: FieldContext<String> = useField('authmethod', {}, { initialValue: "aoauth" })
const drill: FieldContext<String> = useField('drill', {}, { initialValue: 'wss://drill.nadybot.org' })
const enableAllModules: FieldContext<Boolean> = useField('allmodules', {}, { initialValue: false })
const enablePackageManager: FieldContext<Boolean> = useField('packages', {}, { initialValue: true })
const orgbot: FieldContext<Boolean> = useField('orgbot', {}, { initialValue: false }) // todo
// todo timezones

defineExpose({ validate, values })
</script>