<template>
  <div class="row pl-3">
    <div class="w-25 overflow-auto module-container">
      <ul class="list-group">
        <li
          v-for="module in modules"
          :key="module.name"
          @click="selectModule(module)"
          class="list-group-item d-flex justify-content-between align-items-center module-item"
          :class="{ active: selected && module.name == selected.name }"
        >
          {{ module.name }}
          <tri-toggle
            :initial="getClassForModule(module)"
            :handler="toggleModule.bind(null, module)"
          ></tri-toggle>
        </li>
      </ul>
    </div>
    <div class="w-75 px-5 overflow-auto setting-container" v-if="selected">
      <h1 class="mb-4">{{ selected.name }}</h1>

      <div v-if="selected_settings.length > 0">
        <div class="card w-100">
          <div class="card-header">Settings</div>
          <ul class="list-group list-group-flush">
            <li
              class="list-group-item"
              v-for="setting in selected_settings"
              :key="setting.name"
            >
              <div class="col-9 fake-section">
                {{ setting.description }}
                <span class="custom-muted ml-5">{{ setting.name }}</span>
              </div>

              <div class="col-3 fake-section right">
                <input
                  v-if="setting.type == 'number'"
                  type="number"
                  class="form-control form-control-sm"
                  :value="setting.value"
                />
                <toggle
                  v-if="setting.type == 'bool'"
                  :initial="setting.value"
                ></toggle>
                <select
                  v-if="
                    setting.type == 'options' ||
                    setting.type == 'int_options' ||
                    setting.type == 'discord_channel'
                  "
                  class="form-control custom-select custom-select-sm"
                >
                  <option
                    v-for="option in setting.options"
                    :key="option.name"
                    :selected="setting.value == option.value"
                  >
                    {{ option.name }}
                  </option>
                </select>
                <input
                  v-if="setting.type == 'text'"
                  type="text"
                  class="form-control form-control-sm"
                  :value="setting.value"
                />
                <input
                  v-if="setting.type == 'color'"
                  type="color"
                  class="form-control form-control-sm color-input"
                  :value="findColorFromTag(setting.value)"
                />
                <time-picker
                  v-if="setting.type == 'time'"
                  :initial-value="setting.value"
                />
              </div>
            </li>
          </ul>
        </div>
      </div>

      <div class="mt-3" v-if="selected_events.length > 0">
        <div class="card w-100">
          <div class="card-header">Events</div>
          <ul class="list-group list-group-flush">
            <li
              class="list-group-item d-flex justify-content-between align-items-center event-item"
              v-for="event in selected_events"
              :key="event.event"
            >
              <span
                >{{ event.description }}
                <span class="custom-muted ml-5">{{ event.event }}</span></span
              >
              <toggle :initial="event.enabled"></toggle>
            </li>
          </ul>
        </div>
      </div>

      <div class="mt-3" v-if="selected_commands.length > 0">
        <table class="table table-bordered table-hover">
          <thead>
            <tr class="table-head-light">
              <th scope="col" class="text-nowrap">Command</th>
              <th scope="col" class="desc">Description</th>
              <th scope="col">Org</th>
              <th scope="col">Priv</th>
              <th scope="col">Tell</th>
            </tr>
          </thead>
          <tbody>
            <template
              v-for="command in selected_commands"
              :key="command.command"
            >
              <tr>
                <td :class="{ 'pad-left': command.subcommands.length == 0 }">
                  <fa
                    v-if="command.subcommands.length > 0"
                    @click="selectCommand(command)"
                    :icon="
                      selected_command &&
                      command.command == selected_command.command
                        ? 'chevron-down'
                        : 'chevron-right'
                    "
                    invert="10"
                  ></fa>
                  {{ command.command }}
                </td>
                <td>{{ command.description }}</td>
                <td>
                  <div class="input-group" v-if="command.org">
                    <div class="input-group-prepend">
                      <div class="input-group-text">
                        <input
                          type="checkbox"
                          :checked="command.org.enabled == true"
                        />
                      </div>
                    </div>
                    <select class="form-control custom-select custom-select-sm">
                      <option
                        v-for="access_level in access_levels"
                        :key="access_level.name"
                        :selected="
                          access_level.value == command.org.access_level
                        "
                        :disabled="!access_level.enabled"
                      >
                        {{ access_level.name }}
                      </option>
                    </select>
                  </div>
                  <template v-else>Unavailable</template>
                </td>
                <td>
                  <div class="input-group" v-if="command.priv">
                    <div class="input-group-prepend">
                      <div class="input-group-text">
                        <input
                          type="checkbox"
                          :checked="command.priv.enabled == true"
                        />
                      </div>
                    </div>
                    <select class="form-control custom-select custom-select-sm">
                      <option
                        v-for="access_level in access_levels"
                        :key="access_level.name"
                        :selected="
                          access_level.value == command.priv.access_level
                        "
                        :disabled="!access_level.enabled"
                      >
                        {{ access_level.name }}
                      </option>
                    </select>
                  </div>
                  <template v-else>Unavailable</template>
                </td>
                <td>
                  <div class="input-group" v-if="command.msg">
                    <div class="input-group-prepend">
                      <div class="input-group-text">
                        <input
                          type="checkbox"
                          :checked="command.msg.enabled == true"
                        />
                      </div>
                    </div>
                    <select class="form-control custom-select custom-select-sm">
                      <option
                        v-for="access_level in access_levels"
                        :key="access_level.name"
                        :selected="
                          access_level.value == command.msg.access_level
                        "
                        :disabled="!access_level.enabled"
                      >
                        {{ access_level.name }}
                      </option>
                    </select>
                  </div>
                  <template v-else>Unavailable</template>
                </td>
              </tr>

              <template
                v-if="
                  selected_command &&
                  command.command == selected_command.command
                "
              >
                <tr
                  v-for="subcommand in command.subcommands"
                  :key="subcommand.command"
                >
                  <td class="pad-left border-left-thick">
                    {{ subcommand.command }}
                  </td>
                  <td>{{ subcommand.description }}</td>
                  <td>
                    <div class="input-group" v-if="subcommand.org">
                      <div class="input-group-prepend">
                        <div class="input-group-text">
                          <input
                            type="checkbox"
                            :checked="subcommand.org.enabled == true"
                          />
                        </div>
                      </div>
                      <select
                        class="form-control custom-select custom-select-sm"
                      >
                        <option
                          v-for="access_level in access_levels"
                          :key="access_level.name"
                          :selected="
                            access_level.value == subcommand.org.access_level
                          "
                          :disabled="!access_level.enabled"
                        >
                          {{ access_level.name }}
                        </option>
                      </select>
                    </div>
                    <template v-else>Unavailable</template>
                  </td>
                  <td>
                    <div class="input-group" v-if="subcommand.priv">
                      <div class="input-group-prepend">
                        <div class="input-group-text">
                          <input
                            type="checkbox"
                            :checked="subcommand.priv.enabled == true"
                          />
                        </div>
                      </div>
                      <select
                        class="form-control custom-select custom-select-sm"
                      >
                        <option
                          v-for="access_level in access_levels"
                          :key="access_level.name"
                          :selected="
                            access_level.value == subcommand.priv.access_level
                          "
                          :disabled="!access_level.enabled"
                        >
                          {{ access_level.name }}
                        </option>
                      </select>
                    </div>
                    <template v-else>Unavailable</template>
                  </td>
                  <td>
                    <div class="input-group" v-if="subcommand.msg">
                      <div class="input-group-prepend">
                        <div class="input-group-text">
                          <input
                            type="checkbox"
                            :checked="subcommand.msg.enabled == true"
                          />
                        </div>
                      </div>
                      <select
                        class="form-control custom-select custom-select-sm"
                      >
                        <option
                          v-for="access_level in access_levels"
                          :key="access_level.name"
                          :selected="
                            access_level.value == subcommand.msg.access_level
                          "
                          :disabled="!access_level.enabled"
                        >
                          {{ access_level.name }}
                        </option>
                      </select>
                    </div>
                    <template v-else>Unavailable</template>
                  </td>
                </tr>
              </template>
            </template>
          </tbody>
        </table>
      </div>
    </div>
    <h2 class="pl-5" v-else>Select a module to change its configuation.</h2>
  </div>
</template>

<style lang="scss" scoped>
.custom-muted {
  display: none;
  color: lighten(#6c757d, 20%);
}

.event-item:hover {
  .custom-muted {
    display: inline;
  }
}

.module-item:hover:not(.active) {
  color: white;
  background-color: rgba(0, 123, 255, 0.9);
}

.table-head-light {
  background-color: rgba(0, 0, 0, 0.03);
}

.table td,
.table.th {
  vertical-align: middle;
}

.module-container {
  height: 96vh;
}

.setting-container {
  height: 98vh;
}

.fake-section {
  display: inline-block;
  padding: 0;
}

.right {
  text-align: right;
}

.color-input {
  height: 1.6em;
}

.pad-left {
  padding-left: 30px;
}

.pad-left-2 {
  padding-left: 60px;
}

td {
  white-space: nowrap;
}

.border-left-thick {
  border-left: 3px solid #424242;
}
</style>

<script lang="ts">
import { defineComponent } from "vue";

import {
  getModules,
  getModuleSettings,
  getModuleEvents,
  getModuleCommands,
  getAccessLevels,
  toggleModule,
} from "@/nadybot/http";
import {
  ConfigModule,
  ModuleAccessLevel,
  ModuleCommand,
  ModuleEventConfig,
  ModuleSetting,
} from "@/nadybot/types/settings";

interface SettingsData {
  access_levels: Array<ModuleAccessLevel>;
  modules: Array<ConfigModule>;
  selected: ConfigModule | null;
  selected_settings: Array<ModuleSetting>;
  selected_commands: Array<ModuleCommand>;
  selected_events: Array<ModuleEventConfig>;
  selected_command: ModuleCommand | null;
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
    selectModule: async function (module: ConfigModule): Promise<void> {
      let settings = await getModuleSettings(module.name);
      settings = settings.filter(function (val) {
        return val.editable == true;
      });
      let commands = await getModuleCommands(module.name);
      let events = await getModuleEvents(module.name);
      this.selected_settings = settings;
      this.selected_commands = commands;
      this.selected_events = events;
      this.selected = module;
    },
    findColorFromTag: function (text: string): string | null {
      let re = /#[0-9a-f]{3,6}/i;
      let matches = text.match(re);
      if (matches) {
        return matches[0];
      }
      return null;
    },
    selectCommand: function (command: ModuleCommand): void {
      if (
        this.selected_command &&
        this.selected_command.command == command.command
      ) {
        this.selected_command = null;
      } else {
        this.selected_command = command;
      }
    },
    toggleModule: async function (
      module: ConfigModule,
      enabled: boolean
    ): Promise<void> {
      await toggleModule(module.name, enabled);

      if (this.selected && this.selected.name == module.name) {
        await this.selectModule(module);
      }

      if (module.name == "RAID_MODULE") {
        let access_levels = await getAccessLevels();
        access_levels.sort(function (a, b) {
          return a.numeric_value - b.numeric_value;
        });
        this.access_levels = access_levels;
      }
    },
  },

  data(): SettingsData {
    return {
      access_levels: [],
      modules: [],
      selected: null,
      selected_settings: [],
      selected_commands: [],
      selected_events: [],
      selected_command: null,
    };
  },

  async created() {
    let modules = await getModules();
    let access_levels = await getAccessLevels();
    access_levels.sort(function (a, b) {
      return a.numeric_value - b.numeric_value;
    });
    console.log(
      `Loaded ${modules.length} modules and ${access_levels.length} access levels`
    );
    this.modules = modules;
    this.access_levels = access_levels;
  },
});
</script>
