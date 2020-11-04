<template>
  <sidebar :items="items"></sidebar>
  <div class="content-container pt-3">
    <div class="container-fluid">
      <router-view />
    </div>
  </div>
</template>

<style lang="scss">
.content-container {
  padding-left: 220px;
}
</style>

<script lang="ts">
import "bootstrap/dist/css/bootstrap.min.css";

export default {
  name: "nadyui",

  data(): Record<string, Array<Record<string, string>>> {
    return {
      items: [
        {
          name: "Home",
          path: "/",
          icon: "home",
        },
        {
          name: "Users",
          path: "/users",
          icon: "user",
        },
        {
          name: "Settings",
          path: "/settings",
          icon: "cog",
        },
      ],
    };
  },
};
</script>
