<template>
  <li>
    <router-link :to="$props.path"
      ><fa :icon="$props.icon" /> {{ name }}</router-link
    >
  </li>
</template>

<script lang="ts">
import { defineComponent } from "vue";

export default defineComponent({
  name: "SidebarItem",

  props: {
    path: {
      type: String,
      required: true,
    },
    icon: {
      type: String,
      required: true,
    },
    name: {
      type: String,
      required: true,
    },
  },
});
</script>
