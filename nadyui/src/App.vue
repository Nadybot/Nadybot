<template>
  <sidebar :items="items"></sidebar>
  <div class="content-container pt-3">
    <div class="container-fluid">
      <router-view />
    </div>
  </div>
</template>

<style lang="scss">
// Some weird Firefox bug resolves system-ui to Cantarell no matter what
// Roboto has fixed-width digits and looks good, Verdana is the in-game font
body {
  font-family: Roboto, Verdana, system-ui, -apple-system, "Segoe UI",
    "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif,
    "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
}

.content-container {
  padding-left: 220px;
}
</style>

<script lang="ts">
import "bootstrap/dist/css/bootstrap.min.css";
import "@/styles/switch-sizing.scss";
import "@/styles/callout.scss";
import "@/styles/dark.scss";

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
        {
          name: "Console",
          path: "/console",
          icon: "terminal",
        },
        {
          name: "Chat",
          path: "/chat",
          icon: "comment",
        },
      ],
    };
  },
};
</script>
