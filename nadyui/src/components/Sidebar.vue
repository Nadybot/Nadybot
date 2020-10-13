<template>
  <div class="sidebar-container">
    <div class="sidebar-logo">
      <img src="../assets/logo.png" />
    </div>
    <ul class="sidebar-navigation">
      <li class="header">Navigation</li>
      <sidebar-item
        v-for="item in items"
        :key="item.name"
        :path="item.path"
        :icon="item.icon"
        :name="item.name"
      ></sidebar-item>
    </ul>
  </div>
</template>

<style lang="scss" scoped>
.sidebar-container {
  position: fixed;
  width: 220px;
  height: 100%;
  left: 0;
  overflow-x: hidden;
  overflow-y: auto;
  background: #1a1a1a;
  color: #fff;
}

.sidebar-logo {
  font-size: 20px;
  background-color: #2574a9;
  padding-bottom: 15px;

  img {
    max-width: 100%;
  }
}

.sidebar-navigation {
  padding: 0;
  margin: 0;
  list-style-type: none;
  position: relative;

  .header {
    font-size: 12px;
    text-transform: uppercase;
    background-color: #151515;
    padding: 10px 15px 10px 30px;

    &::before {
      background-color: transparent;
    }
  }
}
</style>

<script lang="ts">
import { defineComponent } from "vue";
import SidebarItem from "@/components/SidebarItem.vue";

export default defineComponent({
  name: "Sidebar",

  components: {
    SidebarItem,
  },

  props: {
    items: {
      type: Array,
      required: true,
    },
  },
});
</script>
