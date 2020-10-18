<template>
  <table class="table table-hover table-striped">
    <thead class="thead-dark">
      <tr>
        <th scope="col">Main</th>
        <th scope="col">Profession</th>
        <th scope="col">Character</th>
        <th scope="col">Level</th>
        <th scope="col">Org</th>
        <th scope="col">Rank</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="user in allUsers" :key="user.name">
        <td>{{ user.main_character }}</td>
        <td>
          <profession-icon :profession="user.profession" />
          {{ user.profession }}
        </td>
        <td>{{ user.name }}</td>
        <td>
          {{ user.level }}
          <span
            class="badge badge-ai badge-pill badge-success"
            v-if="user.ai_level > 0"
            >{{ user.ai_level }}</span
          >
        </td>
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
  </table>
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
    background-color: #afafaf;
    border: thin solid grey;
  }
}

.badge-ai {
  vertical-align: text-top;
}
</style>

<script lang="ts">
import { mapGetters } from "vuex";
import { defineComponent } from "vue";

const Component = defineComponent({
  name: "UsersList",

  computed: {
    ...mapGetters(["allUsers"]),
  },
});

export default Component;
</script>
