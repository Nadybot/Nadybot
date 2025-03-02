<template>
  <form class="pt-6 w-50 mx-auto">
    <legend class="text-h4 mb-6">Configure the database</legend>

    <v-select
      :error-messages="type.errorMessage.value"
      label="Type"
      :items="props.databases"
      v-model="type.value.value"
      :disabled="props.databases.length == 1"
      v-on:update:model-value="changeDefaults"
    ></v-select>

    <template v-if="type.value.value == 'sqlite'">
      <v-text-field
        v-model="host.value.value"
        :error-messages="host.errorMessage.value"
        label="Database file directory"
      ></v-text-field>

      <v-text-field
        v-model="name.value.value"
        :error-messages="name.errorMessage.value"
        label="Database file name"
      ></v-text-field>

      <v-alert icon="$info" color="blue-lighten-5">
        <p>
          SQLite is a file-based database that needs a directory where to store the file, and a file
          name (preferably with the ending <code>.db</code>). Because it's file-based, you cannot
          share an SQLite database between multiple bots.
        </p>
        <p>
          Due to the simplicity of the database, it's the recommended database system for Nadybot.
        </p>
      </v-alert>
    </template>
    <template v-else-if="type.value.value != undefined">
      <v-text-field
        v-model="host.value.value"
        :error-messages="host.errorMessage.value"
        label="Database host"
      ></v-text-field>

      <v-text-field
        v-model="name.value.value"
        :error-messages="name.errorMessage.value"
        label="Database name"
      ></v-text-field>

      <v-text-field
        v-model="username.value.value"
        :error-messages="username.errorMessage.value"
        label="Database username"
      ></v-text-field>

      <v-text-field
        v-model="password.value.value"
        :error-messages="password.errorMessage.value"
        label="Database password"
      ></v-text-field>

      <v-alert icon="$info" color="blue-lighten-5">
        <p>
          Use a {{ type.value }} database, if you already have a dedicated database server, or you
          want to share the database between multiple bots.
        </p>
        <p>Sharing the database between bots allows sharing alts.</p>
        <p>
          A dedicated database is usually not as fast as an SQLite database, but uses less memory in
          the PHP process.
        </p>
      </v-alert>
    </template>
  </form>
</template>

<script lang="ts" setup>
import { useField, useForm, type FieldContext } from 'vee-validate'

const { validate, values } = useForm({
  validationSchema: {},
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

const props = defineProps({ databases: { type: Array<string>, required: true } })
const type: FieldContext<string | undefined> = useField(
  'type',
  {},
  { initialValue: props.databases.length == 1 ? props.databases[0] : undefined },
)
const host: FieldContext<string> = useField('host', {})
const name: FieldContext<string> = useField('name')
const password: FieldContext<string> = useField('password')
const username: FieldContext<string> = useField('username')

defineExpose({ validate, values })
</script>
