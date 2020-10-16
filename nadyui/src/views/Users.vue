<template>
  <table class="table table-hover">
    <thead class="thead-dark">
      <tr>
        <th scope="col">Name</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="user in allUsers" :key="user.name">
        <td>{{ user.name }}</td>
      </tr>
    </tbody>
  </table>
</template>

<script lang="ts">
import { mapMutations, mapGetters } from "vuex";
import { defineComponent } from "vue";

const Component = defineComponent({
  name: "UsersList",

  computed: {
    ...mapGetters(["allUsers"]),
  },

  methods: {
    ...mapMutations(["loadOnlineUsers"]),
  },
});

export default Component;
</script>
