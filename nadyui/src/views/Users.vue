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
        <td>{{ user.level }}/{{ user.ai_level }}</td>
        <td>
          <span class="badge" :class="user.faction.toLowerCase()">{{
            user.org
          }}</span>
        </td>
        <td>{{ user.org_rank }}</td>
      </tr>
    </tbody>
  </table>
</template>

<style lang="scss" scoped>
.badge {
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
