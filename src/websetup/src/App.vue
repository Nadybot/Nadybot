<template>
  <v-container>
    <div class="text-h2 mb-6">Nadybot Setup</div>

    <template v-if="currentStep < 7">
      <v-stepper v-model="currentStep">
        <v-stepper-header>
          <v-stepper-item
            title="Credentials"
            :value="1"
            :error="!errors[0]"
            :complete="currentStep > 1 ? errors[0] : undefined"
          ></v-stepper-item>

          <v-stepper-item
            title="Character"
            :value="2"
            :error="!errors[1]"
            :complete="currentStep > 2 ? errors[1] : undefined"
          ></v-stepper-item>

          <v-stepper-item
            title="Workers"
            :value="3"
            :error="!errors[2]"
            :complete="currentStep > 3 ? errors[2] : undefined"
          ></v-stepper-item>

          <v-stepper-item
            title="Superadmin"
            :value="4"
            :error="!errors[3]"
            :complete="currentStep > 4 ? errors[3] : undefined"
          ></v-stepper-item>

          <v-stepper-item
            title="Database"
            :value="5"
            :error="!errors[4]"
            :complete="currentStep > 5 ? errors[4] : undefined"
          ></v-stepper-item>

          <v-stepper-item
            title="Extra Settings"
            :value="6"
            :error="!errors[5]"
            :complete="currentStep > 6 ? errors[5] : undefined"
          ></v-stepper-item>
        </v-stepper-header>

        <v-stepper-window>
          <v-stepper-window-item :value="1">
            <Credentials ref="credentials"></Credentials>

            <v-stepper-actions @click:next="validateStep()"></v-stepper-actions>
          </v-stepper-window-item>

          <v-stepper-window-item :value="2">
            <Character :characters="characters" ref="character"></Character>

            <v-stepper-actions
              @click:prev="currentStep = 1"
              @click:next="validateStep()"
            ></v-stepper-actions>
          </v-stepper-window-item>

          <v-stepper-window-item :value="3">
            <span>Workers are currently not implemented and must be manually configured.</span>

            <v-stepper-actions
              @click:prev="currentStep = 2"
              @click:next="validateStep()"
            ></v-stepper-actions>
          </v-stepper-window-item>

          <v-stepper-window-item :value="4">
            <SuperAdmin ref="superadmin"></SuperAdmin>

            <v-stepper-actions
              @click:prev="currentStep = 3"
              @click:next="validateStep()"
            ></v-stepper-actions>
          </v-stepper-window-item>

          <v-stepper-window-item :value="5">
            <Database :databases="databases" ref="database"></Database>

            <v-stepper-actions
              @click:prev="currentStep = 4"
              @click:next="validateStep()"
            ></v-stepper-actions>
          </v-stepper-window-item>

          <v-stepper-window-item :value="6">
            <ExtraSettings
              ref="extrasettings"
              :orgName="orgName"
              :timezones="timezones"
            ></ExtraSettings>

            <v-stepper-actions
              @click:prev="currentStep = 5"
              @click:next="validateStep()"
              next-text="Save settings"
              :disabled="false"
            ></v-stepper-actions>
          </v-stepper-window-item>
        </v-stepper-window>
      </v-stepper>
    </template>
    <template v-else>
      <v-alert
        text="Your settings have been saved. The bot is now starting, please check back in your terminal!"
        title="Settings saved"
        type="success"
      ></v-alert>
    </template>
  </v-container>

  <v-dialog v-model="showLoading" max-width="320" persistent>
    <v-list class="py-2" color="primary" elevation="12" rounded="lg">
      <v-list-item prepend-icon="$download" :title="loadingText">
        <template v-slot:prepend>
          <div class="pe-4">
            <v-icon color="primary" size="x-large"></v-icon>
          </div>
        </template>

        <template v-slot:append>
          <v-progress-circular
            color="primary"
            indeterminate="disable-shrink"
            size="16"
            width="2"
          ></v-progress-circular>
        </template>
      </v-list-item>
    </v-list>
  </v-dialog>

  <v-dialog v-model="showErrorPopup" max-width="500">
    <v-card :text="errorPopupText" title="An Error Occurred">
      <template v-slot:actions>
        <v-spacer></v-spacer>

        <v-btn @click="showErrorPopup = false"> Dismiss </v-btn>
      </template>
    </v-card>
  </v-dialog>
</template>

<style scoped></style>

<script lang="ts" setup>
import { ref, useTemplateRef, type Ref } from 'vue'
import Credentials from './components/Credentials.vue'
import Character, { type CharacterOption } from './components/Character.vue'
import SuperAdmin from './components/SuperAdmin.vue'
import Database from './components/Database.vue'
import ExtraSettings from './components/ExtraSettings.vue'

type CredentialsType = InstanceType<typeof Credentials>
type CharacterType = InstanceType<typeof Character>
type SuperAdminType = InstanceType<typeof SuperAdmin>
type DatabaseType = InstanceType<typeof Database>
type ExtraSettingsType = InstanceType<typeof ExtraSettings>

const currentStep = ref(1)

const showLoading = ref(false)
const loadingText = ref('')
const showErrorPopup = ref(false)
const errorPopupText = ref('')

const credentials = useTemplateRef<CredentialsType>('credentials')
const character = useTemplateRef<CharacterType>('character')
const superadmin = useTemplateRef<SuperAdminType>('superadmin')
const database = useTemplateRef<DatabaseType>('database')
const extrasettings = useTemplateRef<ExtraSettingsType>('extrasettings')

const errors = ref([true, true, true, true, true, true])

interface Character {
  uid: number
  name: string
  level: number
  online: boolean
}

interface SystemSpecs {
  bot_version: string
  php_version: string
  os: string
  databases: Array<string>
}

const characters: Ref<Array<CharacterOption>> = ref([])
const databases: Ref<Array<string>> = ref([])
const orgName: Ref<string> = ref('')
const timezones: Ref<Array<string>> = ref([])

const fetchCharacters = async () => {
  loadingText.value = 'Loading characters...'
  showLoading.value = true
  const resp = await fetch(
    '/characters?' + new URLSearchParams(credentials.value!.values).toString(),
  )
  showLoading.value = false

  if (resp.status == 401) {
    errorPopupText.value = 'Login or password invalid'
    showErrorPopup.value = true
    return false
  } else if (resp.status == 408) {
    errorPopupText.value = 'Timeout getting character list'
    showErrorPopup.value = true
    return false
  } else if (resp.status == 500) {
    errorPopupText.value = 'Error getting list of characters'
    showErrorPopup.value = true
    return false
  }

  const json: Array<Character> = await resp.json()

  if (json.length == 0) {
    errorPopupText.value = 'No characters found'
    showErrorPopup.value = true
    return false
  }

  characters.value = []
  for (const entry in json) {
    const item = json[entry]
    characters.value.push({ display: `${item.name} (Level ${item.level})`, value: item.name })
  }

  return true
}

const fetchCharacterOrg = async () => {
  loadingText.value = 'Fetching character info...'
  showLoading.value = true
  const resp = await fetch(
    `https://bork.aobots.org/character/bio/d/${credentials.value!.values.dimension}/name/${character.value!.values.character}/bio.xml?data_type=json`,
  )
  showLoading.value = false

  if (resp.status == 200) {
    const json = await resp.json()

    if (json == null) {
      errorPopupText.value = 'Could not find character'
      showErrorPopup.value = true
      return false
    }
    if (json[1] == null) orgName.value = ''
    else orgName.value = json[1]['NAME']
    return true
  } else {
    errorPopupText.value = 'Error fetching character info'
    showErrorPopup.value = true
    return false
  }
}

const fetchSuperadmin = async () => {
  loadingText.value = 'Fetching superadmin info...'
  showLoading.value = true
  let resp = await fetch(
    `https://bork.aobots.org/character/bio/d/${credentials.value!.values.dimension}/name/${superadmin.value!.values.superadmin}/bio.xml?data_type=json`,
  )

  if (resp.status == 200) {
    const json = await resp.json()

    if (json == null) {
      showLoading.value = false
      errorPopupText.value = 'Could not find superadmin character'
      showErrorPopup.value = true
      return false
    }

    loadingText.value = 'Loading databases...'
    resp = await fetch('/specs')
    showLoading.value = false

    if (resp.status == 500) {
      errorPopupText.value = 'Error getting database list'
      showErrorPopup.value = true
      return false
    }

    const specs: SystemSpecs = await resp.json()

    databases.value = specs.databases

    return true
  } else {
    showLoading.value = false
    errorPopupText.value = 'Error fetching superadmin info'
    showErrorPopup.value = true
    return false
  }
}

const fetchTimezones = async () => {
  loadingText.value = 'Fetching timezones...'
  showLoading.value = true
  const resp = await fetch('/timezones')
  showLoading.value = false

  if (resp.status == 500) {
    errorPopupText.value = 'Error fetching timezones'
    showErrorPopup.value = true
    return false
  }

  const json: Map<string, Array<string>> = await resp.json()

  timezones.value = []
  for (const [_, value] of Object.entries(json)) {
    timezones.value.push(...value)
  }

  return true
}

const saveSettings = async () => {
  loadingText.value = 'Saving settings...'
  showLoading.value = true

  const config = {
    database: {
      host: database.value!.values.host,
      name: database.value!.values.name,
      password: database.value!.values.password,
      type: database.value!.values.type,
      username: database.value!.values.username,
    },
    general: {
      default_module_status: extrasettings.value!.values.enableAllModules,
      enable_package_module: extrasettings.value!.values.enablePackageManager,
      org_name: extrasettings.value!.values.orgbot ? orgName.value : '',
      super_admins: [superadmin.value!.values.superadmin],
      timezone: extrasettings.value!.values.timezone,
    },
    main: {
      character: character.value!.values.character,
      dimension: credentials.value!.values.dimension,
      login: credentials.value!.values.login,
      password: credentials.value!.values.password,
    },
    paths: {},
    settings: {},
  }

  if (extrasettings.value!.values.webui) {
    config.settings = {
      webserver_addr: extrasettings.value!.values.host,
      webserver_port: parseInt(extrasettings.value!.values.port),
      webserver_auth: extrasettings.value!.values.authMethod,
      drill_server: extrasettings.value!.values.drill,
    }
  }

  const resp = await fetch('/config', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(config),
  })

  showLoading.value = false

  if (resp.status == 204) {
    return true
  } else {
    errorPopupText.value = 'Error saving settings'
    showErrorPopup.value = true
    return false
  }
}

const validateStep = async () => {
  const validations = [
    {
      validate: credentials.value?.validate,
      callback: fetchCharacters,
    },
    {
      validate: character.value?.validate,
      callback: fetchCharacterOrg,
    },
    null,
    {
      validate: superadmin.value?.validate,
      callback: fetchSuperadmin,
    },
    {
      validate: database.value?.validate,
      callback: fetchTimezones,
    },
    {
      validate: extrasettings.value?.validate,
      callback: saveSettings,
    },
  ]
  const validation = validations[currentStep.value - 1]
  const isValid = validation ? (await validation.validate!()).valid : true

  if (isValid) {
    if (validation && validation.callback) {
      const result = await validation.callback()
      if (!result) {
        errors.value[currentStep.value - 1] = false
        return
      }
    }
    errors.value[currentStep.value - 1] = true
    currentStep.value = currentStep.value + 1
  } else {
    errors.value[currentStep.value - 1] = false
  }
}

window.addEventListener('keydown', function (event) {
  if (event.key == 'Enter') {
    event.preventDefault()
    validateStep()
  }
})
</script>
