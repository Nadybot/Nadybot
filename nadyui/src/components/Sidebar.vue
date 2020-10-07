<template>
  <nav id="sidebar" class="active">
    <h1>
      <router-link to="/" class="logo">{{ title }}</router-link>
    </h1>
    <ul class="list-unstyled components mb-5">
      <sidebar-item
        v-for="item in items"
        :key="item.name"
        :path="item.path"
        :icon="item.icon"
        :name="item.name"
      ></sidebar-item>
    </ul>
  </nav>
</template>

<style scoped lang="scss"></style>

<script lang="ts">
import { defineComponent } from "vue";
import SidebarItem from "@/components/SidebarItem.vue";

export default defineComponent({
  name: "Sidebar",

  components: {
    SidebarItem,
  },

  props: {
    title: {
      type: String,
      required: true,
    },
  },

  data() {
    return {
      // Sample data
      items: [
        {
          name: "Home",
          icon: "home",
          path: "/",
        },
        {
          name: "Users",
          icon: "user",
          path: "/users",
        },
      ],
    };
  },
});
</script>
