import { createApp } from 'vue'
import App from './App.vue'

import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import { aliases, mdi } from 'vuetify/iconsets/mdi-svg'
import { mdiDownload, mdiEye, mdiEyeOff, mdiInformationVariant } from '@mdi/js'

const vuetify = createVuetify({
  icons: {
    defaultSet: 'mdi',
    aliases: {
      ...aliases,
      download: mdiDownload,
      eye: mdiEye,
      eyeOff: mdiEyeOff,
      info: mdiInformationVariant,
    },
    sets: {
      mdi,
    },
  },
})

createApp(App).use(vuetify).mount('#app')
