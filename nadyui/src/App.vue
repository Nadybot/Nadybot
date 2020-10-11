<template>
  <div id="app">
    <Sidebar title="nadyui"></Sidebar>
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
