<template>
  <user-card
    title="hello"
    imageUrl="https://web-cdn.funcom.com/ao/face/40210.jpg"
    content="lorem ipsum dolor sit amet"
  ></user-card>
  <button class="btn btn-large btn-primary" :onclick="loadOnlineUsers">
    Load Users Test Button
  </button>
</template>

<script lang="ts">
import { mapMutations } from "vuex";

export default {
  name: "UsersList",
  methods: {
    ...mapMutations(["loadOnlineUsers"]),
  },
};
</script>
