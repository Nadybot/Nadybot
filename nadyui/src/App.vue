<template>
  <div id="app">
    <Sidebar title="nadyui"></Sidebar>
    <router-view />
  </div>
</template>

<style lang="scss"></style>

<script lang="ts">
import "bootstrap/dist/css/bootstrap.min.css";
import Sidebar from "@/components/Sidebar.vue";

export default {
  name: "example",
  components: {
    Sidebar,
  },
  data(): Record<string, unknown> {
    return {
      items: [
        {
          name: "Home",
          target: "Home",
          icon: "home",
        },
        {
          name: "Users",
          target: "Users",
          icon: "user",
        },
      ],
    };
  },
};
</script>
