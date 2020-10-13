<template>
  <div id="app">
    <sidebar :items="items"></sidebar>
    <div class="content-container">
      <div class="container-fluid">
        <router-view />
      </div>
    </div>
  </div>
</template>

<style lang="scss">
.content-container {
  padding-top: 20px;
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
      ],
    };
  },
};
</script>
