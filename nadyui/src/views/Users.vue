<template>
  <div
    v-if="users_failed && showAlert"
    class="alert alert-danger alert-dismissible"
    role="alert"
  >
    Fetching online members failed, ONLINE_MODULE might be disabled.
    <a class="alert-link hoverable" @click="enableOnlineModule()">Enable now</a>
    <button
      type="button"
      class="btn-close"
      aria-label="Close"
      @click="showAlert = false"
    ></button>
  </div>
  <v-table
    :data="allUsers"
    class="table table-hover table-striped"
    v-bind:class="{ 'table-xs': allUsers.length > 18 }"
  >
    <template v-slot:head>
      <thead class="table-dark">
        <tr>
          <v-th sortKey="name" defaultSort="asc" scope="col">Character</v-th>
          <v-th sortKey="profession" scope="col">Profession</v-th>
          <v-th :customSort="levelSort" scope="col">Level</v-th>
          <v-th sortKey="main_character" scope="col">Main</v-th>
          <v-th sortKey="org" scope="col">Org</v-th>
          <v-th sortKey="org_rank" scope="col">Rank</v-th>
        </tr>
      </thead>
    </template>
    <template v-slot:body="{ displayData }">
      <tbody>
        <tr v-for="user in displayData" :key="user.name">
          <td>{{ user.name }}</td>
          <td>
            <profession-icon :profession="user.profession" />
            {{ user.profession }}
          </td>
          <td>
            {{ user.level }}
            <span
              class="badge badge-ai rounded-pill bg-success"
              v-if="user.ai_level > 0"
              >{{ user.ai_level }}</span
            >
          </td>
          <td>{{ user.main_character }}</td>
          <td>
            <span
              class="badge badge-faction"
              :class="user.faction.toLowerCase()"
              >{{ user.org }}</span
            >
          </td>
          <td>{{ user.org_rank }}</td>
        </tr>
      </tbody>
    </template>
  </v-table>
</template>

<style lang="scss" scoped>
.badge-faction {
  color: black;

  &.omni {
    background-color: #00ffff;
  }

  &.clan {
    background-color: #f79410;
  }

  &.neutral {
    background-color: #efdecd;
    border: thin solid grey;
  }
}

.badge-ai {
  vertical-align: text-top;
}

.table th {
  user-select: none;
}

.hoverable:hover {
  cursor: pointer;
}
</style>

<script lang="ts">
import { mapGetters, mapMutations, mapState } from "vuex";
import { defineComponent } from "vue";
import { OnlinePlayer } from "@/nadybot/types/player";
import { toggleModule } from "@/nadybot/http";

interface AlertData {
  showAlert: boolean;
}

export default defineComponent({
  name: "UsersList",

  data(): AlertData {
    return {
      showAlert: true,
    };
  },

  methods: {
    async enableOnlineModule(): Promise<void> {
      await toggleModule("ONLINE_MODULE", true);
      await this.loadOnlineUsers();
      this.showAlert = false;
    },
    // Sort two players by their leve primarily and AI level secondarily
    levelSort(a: OnlinePlayer, b: OnlinePlayer): number {
      if (a.level == b.level) {
        if (a.ai_level > b.ai_level) {
          return 1;
        } else if (a.ai_level == b.ai_level) {
          return 0;
        } else {
          return -1;
        }
      } else if (a.level > b.level) {
        return 1;
      } else {
        return -1;
      }
    },
    ...mapMutations(["loadOnlineUsers"]),
  },

  computed: {
    ...mapGetters(["allUsers"]),
    ...mapState(["users_failed"]),
  },
});
</script>
