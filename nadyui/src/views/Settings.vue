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
            :model-value="getClassForModule(module)"
            @update:model-value="toggleModule(module, $event)"
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
                  v-model.number="setting.value"
                  @change="changeSetting(setting)"
                />
                <toggle
                  v-if="setting.type == 'bool'"
                  v-model="setting.value"
                  @change="changeSetting(setting)"
                ></toggle>
                <select
                  v-if="
                    setting.type == 'options' ||
                    setting.type == 'discord_channel'
                  "
                  class="form-control custom-select custom-select-sm"
                  v-model="setting.value"
                  @change="changeSetting(setting)"
                >
                  <option
                    v-for="option in setting.options"
                    :key="option.name"
                    :value="option.value"
                  >
                    {{ option.name }}
                  </option>
                </select>
                <select
                  v-if="setting.type == 'int_options'"
                  class="form-control custom-select custom-select-sm"
                  v-model.number="setting.value"
                  @change="changeSetting(setting)"
                >
                  <option
                    v-for="option in setting.options"
                    :key="option.name"
                    :value="option.value"
                  >
                    {{ option.name }}
                  </option>
                </select>
                <input
                  v-if="setting.type == 'text'"
                  type="text"
                  class="form-control form-control-sm"
                  v-model="setting.value"
                  @change="changeSetting(setting)"
                />
                <input
                  v-if="setting.type == 'color'"
                  type="color"
                  class="form-control form-control-sm color-input"
                  :value="findColorFromTag(setting.value)"
                  @input="(e) => (setting.value = e.target.value)"
                  @change="changeSetting(setting)"
                />
                <time-picker
                  v-if="setting.type == 'time'"
                  v-model="setting.value"
                  @change="changeSetting(setting)"
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
              <toggle
                v-model="event.enabled"
                @change="toggleEvent(event)"
              ></toggle>
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
                          :value="command.org.enabled"
                          v-model="command.org.enabled"
                          @change="toggleCommand(command, 'org', command.org)"
                        />
                      </div>
                    </div>
                    <select
                      class="form-control custom-select custom-select-sm"
                      v-model="command.org.access_level"
                      @change="toggleCommand(command, 'org', command.org)"
                    >
                      <option
                        v-for="access_level in access_levels"
                        :key="access_level.name"
                        :selected="
                          access_level.value == command.org.access_level
                        "
                        :disabled="!access_level.enabled"
                        :value="access_level.value"
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
                          :value="command.priv.enabled"
                          v-model="command.priv.enabled"
                          @change="toggleCommand(command, 'priv', command.priv)"
                        />
                      </div>
                    </div>
                    <select
                      class="form-control custom-select custom-select-sm"
                      v-model="command.priv.access_level"
                      @change="toggleCommand(command, 'priv', command.priv)"
                    >
                      <option
                        v-for="access_level in access_levels"
                        :key="access_level.name"
                        :selected="
                          access_level.value == command.priv.access_level
                        "
                        :disabled="!access_level.enabled"
                        :value="access_level.value"
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
                          :value="command.msg.enabled"
                          v-model="command.msg.enabled"
                          @change="toggleCommand(command, 'msg', command.msg)"
                        />
                      </div>
                    </div>
                    <select
                      class="form-control custom-select custom-select-sm"
                      v-model="command.msg.access_level"
                      @change="toggleCommand(command, 'msg', command.msg)"
                    >
                      <option
                        v-for="access_level in access_levels"
                        :key="access_level.name"
                        :selected="
                          access_level.value == command.msg.access_level
                        "
                        :disabled="!access_level.enabled"
                        :value="access_level.value"
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
                            :value="subcommand.org.enabled"
                            v-model="subcommand.org.enabled"
                            @change="
                              toggleCommand(subcommand, 'org', subcommand.org)
                            "
                          />
                        </div>
                      </div>
                      <select
                        class="form-control custom-select custom-select-sm"
                        v-model="subcommand.org.access_level"
                        @change="
                          toggleCommand(subcommand, 'org', subcommand.org)
                        "
                      >
                        <option
                          v-for="access_level in access_levels"
                          :key="access_level.name"
                          :selected="
                            access_level.value == subcommand.org.access_level
                          "
                          :disabled="!access_level.enabled"
                          :value="access_level.value"
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
                            :value="subcommand.priv.enabled"
                            v-model="subcommand.priv.enabled"
                            @change="
                              toggleCommand(subcommand, 'priv', subcommand.priv)
                            "
                          />
                        </div>
                      </div>
                      <select
                        class="form-control custom-select custom-select-sm"
                        v-model="subcommand.priv.access_level"
                        @change="
                          toggleCommand(subcommand, 'priv', subcommand.priv)
                        "
                      >
                        <option
                          v-for="access_level in access_levels"
                          :key="access_level.name"
                          :selected="
                            access_level.value == subcommand.priv.access_level
                          "
                          :disabled="!access_level.enabled"
                          :value="access_level.value"
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
                            :value="subcommand.msg.enabled"
                            v-model="subcommand.msg.enabled"
                            @change="
                              toggleCommand(subcommand, 'msg', subcommand.msg)
                            "
                          />
                        </div>
                      </div>
                      <select
                        class="form-control custom-select custom-select-sm"
                        v-model="subcommand.msg.access_level"
                        @change="
                          toggleCommand(subcommand, 'msg', subcommand.msg)
                        "
                      >
                        <option
                          v-for="access_level in access_levels"
                          :key="access_level.name"
                          :selected="
                            access_level.value == subcommand.msg.access_level
                          "
                          :disabled="!access_level.enabled"
                          :value="access_level.value"
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
    <h2 class="pl-5" v-else>Select a module to change its configuration.</h2>
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
  toggleEvent,
  toggleCommand,
  changeSetting,
} from "@/nadybot/http";
import {
  ConfigModule,
  ModuleAccessLevel,
  ModuleCommand,
  ModuleEventConfig,
  ModuleSetting,
  ModuleSubcommandChannel,
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
      await this.reloadModules();

      if (this.selected && this.selected.name == module.name) {
        await this.selectModule(module);
      }

      if (module.name == "RAID_MODULE") {
        await this.reloadAccessLevels();
      }
    },
    toggleEvent: async function (event: ModuleEventConfig): Promise<void> {
      if (this.selected) {
        await toggleEvent(
          this.selected.name,
          event.event,
          event.handler,
          event.enabled
        );

        await this.reloadModules();
      }
    },
    toggleCommand: async function (
      command: ModuleCommand,
      channel: string,
      config: ModuleSubcommandChannel
    ): Promise<void> {
      if (this.selected) {
        await toggleCommand(
          this.selected.name,
          command.command,
          channel,
          config.access_level,
          config.enabled
        );

        if (this.selected.name == "RAID_MODULE") {
          await this.reloadAccessLevels();
        }

        await this.reloadModules();
      }
    },
    changeSetting: async function (setting: ModuleSetting): Promise<void> {
      if (this.selected) {
        await changeSetting(this.selected.name, setting.name, setting.value);

        if (this.selected.name == "RAID_MODULE") {
          await this.reloadAccessLevels();
        }
      }
    },
    reloadAccessLevels: async function (): Promise<void> {
      let access_levels = await getAccessLevels();
      access_levels.sort(function (a, b) {
        return a.numeric_value - b.numeric_value;
      });
      this.access_levels = access_levels;
    },
    reloadModules: async function (): Promise<void> {
      this.modules = await getModules();
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
