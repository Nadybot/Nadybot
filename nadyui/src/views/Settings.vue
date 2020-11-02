<template>
  <div class="row pl-3">
    <div class="w-25 h-100 overflow-auto">
      <ul class="list-group">
        <li
          v-for="module in modules"
          :key="module.name"
          class="list-group-item d-flex justify-content-between align-items-center"
        >
          {{ module.name }}
          <tri-toggle :initial="getClassForModule(module)"></tri-toggle>
        </li>
      </ul>
    </div>
    <div class="w-60 pl-3">
      <h2>hello world</h2>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import { getModules } from "@/nadybot/http";
import { ConfigModule } from "@/nadybot/types/settings";

interface SettingsData {
  modules: Array<ConfigModule>;
}

export default defineComponent({
  name: "Settings",

  methods: {
    getClassForModule: function (module: ConfigModule): string {
      if (
        module.num_commands_disabled == 0 &&
        module.num_events_disabled == 0
      ) {
        return "all";
      } else if (
        module.num_commands_disabled + module.num_events_disabled > 0 &&
        module.num_commands_enabled + module.num_events_enabled > 0
      ) {
        return "some";
      } else {
        return "none";
      }
    },
  },

  data(): SettingsData {
    return {
      modules: [],
    };
  },

  async created() {
    let modules = await getModules();
    console.log(`Loaded ${modules.length} modules`);
    this.modules = modules;
  },
});
</script>
