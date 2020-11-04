<template>
  <h2 class="mb-3">Dashboard</h2>
  <div v-if="info" class="row">
    <div class="col-sm">
      <div class="card">
        <div class="card-header">Basic Information</div>
        <ul class="list-group list-group-flush">
          <li class="list-group-item">
            <div class="col-3">Bot name:</div>
            <div class="text-right col-9">{{ info.basic.bot_name }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">Superadmin:</div>
            <div class="text-right col-9">{{ info.basic.superadmin }}</div>
          </li>
          <li class="list-group-item" v-if="info.basic.org">
            <div class="col-3">Org:</div>
            <div class="text-right col-9">{{ info.basic.org }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">Bot version:</div>
            <div class="text-right col-9">{{ info.basic.bot_version }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">PHP version:</div>
            <div class="text-right col-9">{{ info.basic.php_version }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">OS:</div>
            <div class="text-right col-9">{{ info.basic.os }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">DB type:</div>
            <div class="text-right col-9">{{ info.basic.db_type }}</div>
          </li>
        </ul>
      </div>

      <div class="card mt-3">
        <div class="card-header">Memory Information</div>
        <div class="card-body">
          <div class="progress">
            <div
              class="progress-bar"
              role="progressbar"
              :aria-valuenow="memoryPercent"
              aria-valuemin="0"
              aria-valuemax="100"
              :style="'width: ' + memoryPercent + '%;'"
            ></div>
          </div>
          <p class="card-text mt-3">
            Currently using {{ memoryCurrentMb }}/{{ memoryPeakMb }}MB of RAM
          </p>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header">Miscellaneous</div>
        <ul class="list-group list-group-flush">
          <li class="list-group-item">
            <div class="col-8">Chat proxy enabled:</div>
            <div class="text-right col-4">{{ info.misc.using_chat_proxy }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-3">Uptime:</div>
            <div class="text-right col-9">{{ uptime }}</div>
          </li>
        </ul>
      </div>
    </div>
    <div class="col-sm">
      <div class="card">
        <div class="card-header">Config</div>
        <ul class="list-group list-group-flush">
          <li class="list-group-item">
            <div class="col-8">Active tell commands:</div>
            <div class="text-right col-4">
              {{ info.config.active_tell_commands }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active priv commands:</div>
            <div class="text-right col-4">
              {{ info.config.active_priv_commands }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active org commands:</div>
            <div class="text-right col-4">
              {{ info.config.active_org_commands }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active subcommands:</div>
            <div class="text-right col-4">
              {{ info.config.active_subcommands }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active aliases:</div>
            <div class="text-right col-4">{{ info.config.active_aliases }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active events:</div>
            <div class="text-right col-4">{{ info.config.active_events }}</div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Active help commands:</div>
            <div class="text-right col-4">
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
            <div class="text-right col-4">
              {{ info.stats.buddy_list_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Max buddy list size:</div>
            <div class="text-right col-4">
              {{ info.stats.max_buddy_list_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Private channel size:</div>
            <div class="text-right col-4">
              {{ info.stats.priv_channel_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Org size:</div>
            <div class="text-right col-4">
              {{ info.stats.org_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Charinfo cache size:</div>
            <div class="text-right col-4">
              {{ info.stats.charinfo_cache_size }}
            </div>
          </li>
          <li class="list-group-item">
            <div class="col-8">Chat queue length:</div>
            <div class="text-right col-4">
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
    memoryPercent(): number {
      if (this.info == null) {
        return 0;
      }
      return Math.floor(
        (this.info.memory.current_usage_real /
          this.info.memory.peak_usage_real) *
          100
      );
    },
    memoryCurrentMb(): number {
      if (this.info == null) {
        return 0;
      }
      return (
        Math.round((this.info.memory.current_usage_real / 1024 / 1024) * 100) /
        100
      );
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
      let date = new Date(0);
      date.setSeconds(this.info.misc.uptime);
      return date.toISOString().substr(11, 8);
    },
  },

  async created(): Promise<void> {
    this.info = await getSystemInformation();
  },
});
</script>
