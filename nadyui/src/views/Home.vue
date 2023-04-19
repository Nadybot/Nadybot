<template>
  <h2 class="mb-3">Dashboard</h2>
  <div v-if="info" class="row">
    <div class="col-sm">
      <div class="card">
        <div class="card-header">Basic Information</div>
        <ul class="list-group list-group-flush">
          <li class="list-group-item">
            <div class="col-3">Bot name:</div>
            <div class="text-end col-9">{{ info.basic.bot_name }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">Superadmins:</div>
            <div class="text-end col-9">
              <ul class="list-inline">
                <li
                  v-for="(superadmin, index) in info.basic.superadmins"
                  :key="superadmin"
                  class="list-inline-item"
                >
                  <span v-if="index + 1 < info.basic.superadmins.length">
                    {{ superadmin }},
                  </span>
                  <span v-else>
                    {{ superadmin }}
                  </span>
                </li>
              </ul>
            </div>
          </li>
          <li class="list-group-item" v-if="info.basic.org">
            <div class="col-3">Org:</div>
            <div class="text-end col-9">{{ info.basic.org }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">Bot version:</div>
            <div class="text-end col-9">{{ info.basic.bot_version }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">PHP version:</div>
            <div class="text-end col-9">{{ info.basic.php_version }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">Event loop:</div>
            <div class="text-end col-9">{{ info.basic.event_loop }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">FS driver:</div>
            <div class="text-end col-9">{{ info.basic.fs }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">OS:</div>
            <div class="text-end col-9">{{ info.basic.os }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">DB type:</div>
            <div class="text-end col-9">{{ info.basic.db_type }}</div>
          </li>
        </ul>
      </div>

      <div class="card mt-3">
        <div class="card-header">Memory Information</div>
        <div class="card-body">
          <p class="card-text">
            Currently using {{ memoryCurrentMb }}MB out of
            {{ memoryAvailableMb }}MB, peak was at {{ memoryPeakMb }}MB of RAM
          </p>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header">Miscellaneous</div>
        <ul class="list-group list-group-flush">
          <li class="list-group-item">
            <div class="col-8">Chat proxy enabled:</div>
            <div class="text-end col-4">{{ info.misc.using_chat_proxy }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">Uptime:</div>
            <div class="text-end col-9">{{ uptime }}</div>
          </li>
        </ul>
      </div>

      <div
        v-if="info.misc.using_chat_proxy && info.misc.proxy_capabilities.name"
        class="card mt-3"
      >
        <div class="card-header">Proxy Capabilities</div>
        <ul class="list-group list-group-flush">
          <li v-if="info.misc.proxy_capabilities.name" class="list-group-item">
            <div class="col-8">Name:</div>
            <div class="text-end col-4">
              {{ info.misc.proxy_capabilities.name }}
            </div>
          </li>
          <li
            v-if="info.misc.proxy_capabilities.version"
            class="list-group-item"
          >
            <div class="col-3">Version:</div>
            <div class="text-end col-9">
              {{ info.misc.proxy_capabilities.version }}
            </div>
          </li>
          <li
            v-if="info.misc.proxy_capabilities.send_modes.length > 0"
            class="list-group-item"
          >
            <div class="col-3">Send Modes:</div>
            <div class="text-end col-9 small-font">
              {{ info.misc.proxy_capabilities.send_modes.join(", ") }}
            </div>
          </li>
          <li
            v-if="info.misc.proxy_capabilities.default_mode"
            class="list-group-item"
          >
            <div class="col-3">Default Mode:</div>
            <div class="text-end col-9">
              {{ info.misc.proxy_capabilities.default_msode }}
            </div>
          </li>
          <li
            v-if="info.misc.proxy_capabilities.started_at"
            class="list-group-item"
          >
            <div class="col-3">Uptime:</div>
            <div class="text-end col-9">{{ proxyUptime }}</div>
          </li>
        </ul>
      </div>
    </div>
    <div class="col-sm">
      <div class="card">
        <div class="card-header">Config</div>
        <ul class="list-group list-group-flush">
          <li
            v-for="active_command_statistic in info.config.active_commands"
            :key="active_command_statistic.name"
            class="list-group-item"
          >
            <div class="col-8">
              Active {{ active_command_statistic.name }} commands:
            </div>
            <div class="text-end col-4">
              {{ active_command_statistic.active_commands }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active subcommands:</div>
            <div class="text-end col-4">
              {{ info.config.active_subcommands }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active aliases:</div>
            <div class="text-end col-4">{{ info.config.active_aliases }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active events:</div>
            <div class="text-end col-4">{{ info.config.active_events }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active help commands:</div>
            <div class="text-end col-4">
              {{ info.config.active_help_commands }}
            </div>
          </li>
        </ul>
      </div>

      <div class="card mt-3">
        <div class="card-header">Stats</div>
        <ul class="list-group list-group-flush">
          <li class="list-group-item">
            <div class="col-8">Buddy list size:</div>
            <div class="text-end col-4">
              {{ info.stats.buddy_list_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Max buddy list size:</div>
            <div class="text-end col-4">
              {{ info.stats.max_buddy_list_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Private channel size:</div>
            <div class="text-end col-4">
              {{ info.stats.priv_channel_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Org size:</div>
            <div class="text-end col-4">
              {{ info.stats.org_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Charinfo cache size:</div>
            <div class="text-end col-4">
              {{ info.stats.charinfo_cache_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Chat queue length:</div>
            <div class="text-end col-4">
              {{ info.stats.chatqueue_length }}
            </div>
          </li>
        </ul>
      </div>
    </div>
    <div class="col-sm">
      <div class="card">
        <div class="card-header">Channels</div>
        <ul class="list-group list-group-flush">
          <li
            v-for="channel in info.channels"
            :key="channel.name"
            class="list-group-item"
          >
            {{ channel.name }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.list-group-item div {
  display: inline-block;
  padding: 0;
}

.small-font {
  font-size: 94%;
}
</style>

<script lang="ts">
import { defineComponent } from "vue";

import { getSystemInformation } from "@/nadybot/http";
import { SystemInformation } from "@/nadybot/types/stats";

interface InfoData {
  info: SystemInformation | null;
}

export default defineComponent({
  name: "Home",

  data(): InfoData {
    return {
      info: null,
    };
  },

  computed: {
    memoryCurrentMb(): number {
      if (this.info == null) {
        return 0;
      }
      return (
        Math.round((this.info.memory.current_usage_real / 1024 / 1024) * 100) /
        100
      );
    },
    memoryAvailableMb(): number {
      if (this.info == null) {
        return 0;
      }
      return Math.round((this.info.memory.available / 1024 / 1024) * 100) / 100;
    },
    memoryPeakMb(): number {
      if (this.info == null) {
        return 0;
      }
      return (
        Math.round((this.info.memory.peak_usage_real / 1024 / 1024) * 100) / 100
      );
    },
    uptime(): string {
      if (this.info == null) {
        return "";
      }
      const date = new Date(0);
      date.setSeconds(this.info.misc.uptime);
      return date.toISOString().substr(11, 8);
    },
    proxyUptime(): string {
      if (
        this.info == null ||
        this.info.misc.proxy_capabilities == undefined ||
        this.info.misc.proxy_capabilities.started_at == null
      ) {
        return "";
      }
      const now = new Date();
      const diff = new Date(
        Math.abs(
          now.getTime() - this.info.misc.proxy_capabilities.started_at * 1000
        )
      );
      return diff.toISOString().substr(11, 8);
    },
  },

  async created(): Promise<void> {
    this.info = await getSystemInformation();
  },
});
</script>
