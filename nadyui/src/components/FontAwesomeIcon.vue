<template>
  <img
    :src="path"
    :height="size"
    :width="size"
    :style="'filter: invert(' + invert + '%);'"
  />
</template>

<script lang="ts">
import { defineComponent } from "vue";

export default defineComponent({
  name: "FontAwesomeIcon",

  props: {
    icon: {
      type: String,
      required: true,
    },
    size: {
      type: Number,
      default: 14,
      required: false,
    },
    invert: {
      type: Number,
      default: 100,
      required: false,
    },
  },

  computed: {
    path(): string {
      return require(`../assets/font-awesome-icons/${this.icon}.svg`);
    },
  },
});
</script>
