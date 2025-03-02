<template>
  <form class="pt-6 w-50 mx-auto">
    <legend class="text-h4 mb-6">Extra settings</legend>

    <v-switch
      color="primary"
      :error-messages="webui.errorMessage.value"
      label="Enable WebUI"
      v-model="webui.value.value"
    ></v-switch>

    <v-alert icon="$info" color="blue-lighten-5" class="mb-5">
      <p class="mb-2">
        The WebUI allows you to configure the bot comfortably from your browser, without the
        limitations of Anarchy Online. The speed is much faster, and you will generally have a
        better time with your bot.
      </p>
      <p>
        You will also get a web chat to talk with people currently in the game, without having to
        log into the game yourself.
      </p>
    </v-alert>

    <template v-if="webui.value.value">
      <v-text-field
        v-model="host.value.value"
        :error-messages="host.errorMessage.value"
        label="Listen address"
      ></v-text-field>

      <v-alert icon="$info" color="blue-lighten-5" class="mb-5">
        <p>
          Use <code>127.0.0.1</code> if you run the bot locally, or through a reverse proxy, like
          Nginx or Drill. Otherwise choose <code>0.0.0.0</code> to make it publicly available.
        </p>
      </v-alert>

      <v-text-field
        v-model="port.value.value"
        :error-messages="port.errorMessage.value"
        label="Listen port"
      ></v-text-field>

      <v-select
        :error-messages="authMethod.errorMessage.value"
        label="Authentication method"
        :items="authTypes"
        item-title="name"
        item-value="value"
        v-model="authMethod.value.value"
      ></v-select>

      <v-alert icon="$info" color="blue-lighten-5" class="mb-5">
        <p class="mb-3">This controls how users will login and authenticate to the WebUI.</p>
        <ul>
          <li class="mb-3">
            <strong>Token</strong>: You get a one-time token when starting the bot, or running a
            bot-command, that will allow you to use the WebUI for 1 hour. Not recommended.
          </li>
          <li>
            <strong>AoAuth</strong>: Use Nadybot's central authentication provider that allows you
            to register your character and their alts via tells in the game, to authenticate as any
            of your characters for 1 month. This doesn't require you to be in-game to use the WebUI
            after you've set up your AoAuth account. This is the recommended way to use the WebUI.
          </li>
        </ul>
      </v-alert>

      <v-select
        :error-messages="drill.errorMessage.value"
        label="Drill server"
        :items="drillTypes"
        item-title="name"
        item-value="value"
        v-model="drill.value.value"
      ></v-select>
      <v-alert icon="$info" color="blue-lighten-5" class="mb-5">
        <p class="mb-3">
          If you don't run a reverse proxy, or don't want to configure anything complicated, and you
          just want to be able to connect to your WebUI from everywhere in a secure manner, choose
          either a EU- or US-based service.
        </p>
        <p class="mb-1">This will make your bot available as either</p>
        <p>
          <code>https://{{ props.botName.toLowerCase() }}.nadybotter.org</code> (US-based)
        </p>
        <p>
          <code>https://{{ props.botName.toLowerCase() }}.nadybotter.eu</code> (EU-based).
        </p>
      </v-alert>
    </template>

    <v-switch
      color="primary"
      :error-messages="enableAllModules.errorMessage.value"
      label="Enable all modules by default"
      v-model="enableAllModules.value.value"
    ></v-switch>

    <v-switch
      color="primary"
      :error-messages="enablePackageManager.errorMessage.value"
      label="Enable the package manager"
      v-model="enablePackageManager.value.value"
    ></v-switch>

    <v-alert icon="$info" color="blue-lighten-5" class="mb-5">
      <p>
        Enable the installation of packages from
        <a href="https://pkg.aobots.org">https://pkg.aobots.org</a> by any bot administrator with
        the <code class="text-no-wrap">!package</code>-command.
      </p>
      <p>You can always limit access to the command to only a specific access level.</p>
    </v-alert>

    <template v-if="props.orgName != ''">
      <v-switch
        color="primary"
        :error-messages="orgbot.errorMessage.value"
        :label="'Enable org-bot functionality (' + props.orgName + ')'"
        v-model="orgbot.value.value"
      ></v-switch>

      <v-alert icon="$info" color="blue-lighten-5" class="mb-5">
        <p>
          Enable this, if you want to use this bot as an org bot, and support bot-ranks based on
          org-ranks
        </p>
      </v-alert>
    </template>

    <v-autocomplete
      label="Default timezone"
      :error-messages="timezone.errorMessage.value"
      v-model="timezone.value.value"
      :items="props.timezones"
    ></v-autocomplete>

    <v-alert icon="$info" color="blue-lighten-5" class="mb-5">
      <p>This changes the timezone that's used by the bot to display date and time.</p>
      <p>If you want to keep this identical to the Anarchy Online time, choose <code>UTC</code>.</p>
    </v-alert>
  </form>
</template>

<script lang="ts" setup>
import { useField, useForm, type FieldContext } from 'vee-validate'
import { ref } from 'vue'

const { validate, values } = useForm({
  validationSchema: {
    port(value: string | null) {
      if (!value) return 'Please enter a port'
      const valueInt = parseInt(value)
      if (isNaN(valueInt)) return 'Not an integer'
      if (valueInt < 1024 || valueInt > 65535) return 'Disallowed port'

      return true
    },
    timezone(value: string | null) {
      if (value != null && props.timezones.indexOf(value) != -1) return true

      return 'Invalid timezone'
    },
  },
})

const authTypes = ref([
  {
    name: 'AoAuth',
    value: 'aoauth',
  },
  {
    name: 'Token',
    value: 'webauth',
  },
])

const drillTypes = ref([
  {
    name: 'Off',
    value: 'off',
  },
  {
    name: 'US-based',
    value: 'wss://drill.us.nadybot.org',
  },
  {
    name: 'EU-based',
    value: 'wss://drill.nadybot.org',
  },
])

const props = defineProps({
  orgName: { type: String, required: true },
  botName: { type: String, required: true },
  timezones: { type: Array<string>, required: true },
})
const webui: FieldContext<boolean> = useField('webui', {}, { initialValue: true })
const host: FieldContext<string> = useField('host', {}, { initialValue: '127.0.0.1' })
const port: FieldContext<string> = useField('port', {}, { initialValue: '8080' })
const authMethod: FieldContext<string> = useField('authMethod', {}, { initialValue: 'aoauth' })
const drill: FieldContext<string> = useField(
  'drill',
  {},
  { initialValue: 'wss://drill.nadybot.org' },
)
const enableAllModules: FieldContext<boolean> = useField(
  'enableAllModules',
  {},
  { initialValue: true },
)
const enablePackageManager: FieldContext<boolean> = useField(
  'enablePackageManager',
  {},
  { initialValue: true },
)
const orgbot: FieldContext<boolean> = useField('orgbot', {}, { initialValue: props.orgName != '' })
const timezone: FieldContext<string> = useField('timezone', {}, { initialValue: 'UTC' })

defineExpose({ validate, values })
</script>
