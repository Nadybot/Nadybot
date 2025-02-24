<template>
  <v-container>
    <div class="text-h2 mb-6">Nadybot Setup</div>

    <v-stepper v-model="currentStep">
      <v-stepper-header>
        <v-stepper-item title="Credentials" :value="1" :error="!errors[0]" :complete="errors[0]"></v-stepper-item>

        <v-stepper-item title="Character" :value="2" :rules="[() => errors[1]]"></v-stepper-item>

        <v-stepper-item title="Workers" :value="3" :rules="[() => errors[2]]"></v-stepper-item>

        <v-stepper-item title="Superadmin" :value="4" :rules="[() => errors[3]]"></v-stepper-item>

        <v-stepper-item title="Database" :value="5" :rules="[() => errors[4]]"></v-stepper-item>

        <v-stepper-item title="Extra Settings" :value="6" :rules="[() => errors[5]]"></v-stepper-item>
      </v-stepper-header>

      <v-stepper-window>
        <v-stepper-window-item :value="1">
          <Credentials ref="credentials"></Credentials>

          <v-stepper-actions @click:next="validateStep(1, fetchCharacters)"></v-stepper-actions>
        </v-stepper-window-item>

        <v-stepper-window-item :value="2">
          <Character :characters="characters" ref="character"></Character>

          <v-stepper-actions @click:prev="currentStep = 1" @click:next="validateStep(2)"></v-stepper-actions>
        </v-stepper-window-item>

        <v-stepper-window-item :value="3">
          <span>Workers are currently not implemented and must be manually configured.</span>

          <v-stepper-actions @click:prev="currentStep = 2" @click:next="currentStep = 4"></v-stepper-actions>
        </v-stepper-window-item>

        <v-stepper-window-item :value="4">
          <SuperAdmin ref="superadmin"></SuperAdmin>

          <v-stepper-actions @click:prev="currentStep = 3"
            @click:next="validateStep(4, fetchDatabases)"></v-stepper-actions>
        </v-stepper-window-item>

        <v-stepper-window-item :value="5">
          <Database :databases="databases" ref="database"></Database>

          <v-stepper-actions @click:prev="currentStep = 4" @click:next="validateStep(5)"></v-stepper-actions>
        </v-stepper-window-item>

        <v-stepper-window-item :value="6">
          <ExtraSettings ref="extrasettings"></ExtraSettings>

          <v-stepper-actions @click:prev="currentStep = 5" @click:next=""></v-stepper-actions>
        </v-stepper-window-item>
      </v-stepper-window>
    </v-stepper>
  </v-container>

  <v-dialog v-model="showLoading" max-width="320" persistent>
    <v-list class="py-2" color="primary" elevation="12" rounded="lg">
      <v-list-item prepend-icon="mdi-download" :title="loadingText">
        <template v-slot:prepend>
          <div class="pe-4">
            <v-icon color="primary" size="x-large"></v-icon>
          </div>
        </template>

        <template v-slot:append>
          <v-progress-circular color="primary" indeterminate="disable-shrink" size="16" width="2"></v-progress-circular>
        </template>
      </v-list-item>
    </v-list>
  </v-dialog>

  <v-dialog v-model="showErrorPopup" max-width="500">
    <v-card :text="errorPopupText" title="An Error Occurred">
      <template v-slot:actions>
        <v-spacer></v-spacer>

        <v-btn @click="showErrorPopup = false">
          Dismiss
        </v-btn>
      </template>
    </v-card>
  </v-dialog>
</template>

<style scoped></style>

<script lang="ts" setup>
import { ref, useTemplateRef, type Ref } from 'vue'
import Credentials from "./components/Credentials.vue"
import Character, { type CharacterOption } from "./components/Character.vue"
import SuperAdmin from './components/SuperAdmin.vue'
import Database from './components/Database.vue'
import ExtraSettings from './components/ExtraSettings.vue'

type CredentialsType = InstanceType<typeof Credentials>
type CharacterType = InstanceType<typeof Character>
type SuperAdminType = InstanceType<typeof SuperAdmin>
type DatabaseType = InstanceType<typeof Database>
type ExtraSettingsType = InstanceType<typeof ExtraSettings>

const currentStep = ref(1);

const showLoading = ref(false);
const loadingText = ref('');
const showErrorPopup = ref(false);
const errorPopupText = ref('');

const credentials = useTemplateRef<CredentialsType>('credentials')
const character = useTemplateRef<CharacterType>('character')
const superadmin = useTemplateRef<SuperAdminType>('superadmin')
const database = useTemplateRef<DatabaseType>('database')
const extrasettings = useTemplateRef<ExtraSettingsType>('extrasettings')

const errors = ref([true, true, true, true, true, true])

type ValidateCallback = () => Promise<boolean | string>;

const validateStep = async (step: number, validate: ValidateCallback | undefined = undefined) => {
  const validations = [credentials.value?.validate, character.value?.validate, null, superadmin.value?.validate, database.value?.validate, extrasettings.value?.validate]
  const isValid = await validations[step - 1]!();

  if (isValid.valid) {
    if (validate) {
      const result = await validate();
      if (!result) {
        errors.value[step - 1] = false;
        return;
      }
    }
    currentStep.value = step + 1;
    errors.value[step - 1] = true;
  } else {
    errors.value[step - 1] = false;
  }
}

interface Character {
  uid: Number,
  name: String,
  level: Number,
  online: Boolean
}

interface SystemSpecs {
  bot_version: String,
  php_version: String,
  os: String,
  databases: Array<String>,
}

const characters: Ref<Array<CharacterOption>> = ref([])
const databases: Ref<Array<String>> = ref([])

const fetchDatabases = async () => {
  loadingText.value = "Loading databases...";
  showLoading.value = true;
  const respp = await fetch("/specs");
  showLoading.value = false;

  if (respp.status == 500) {
    errorPopupText.value = "Error getting database list"
    showErrorPopup.value = true
    return false
  }

  const json: SystemSpecs = await respp.json();

  databases.value = json.databases;

  return true
}

const fetchCharacters = async () => {
  loadingText.value = "Loading characters...";
  showLoading.value = true;
  const resp = await fetch("/characters?" + new URLSearchParams(credentials.value!.values).toString());
  showLoading.value = false;

  if (resp.status == 401) {
    errorPopupText.value = "Login or password invalid"
    showErrorPopup.value = true
    return false
  } else if (resp.status == 408) {
    errorPopupText.value = "Timeout getting character list"
    showErrorPopup.value = true
    return false
  } else if (resp.status == 500) {
    errorPopupText.value = "Error getting list of characters"
    showErrorPopup.value = true
    return false
  }

  const json: Array<Character> = await resp.json();

  if (json.length == 0) {
    errorPopupText.value = "No characters found"
    showErrorPopup.value = true
    return false
  }

  characters.value = []
  for (const entry in json) {
    const item = json[entry]
    characters.value.push({ display: `${item.name} (Level ${item.level})`, value: item.name })
  }

  return true;
}
</script>
