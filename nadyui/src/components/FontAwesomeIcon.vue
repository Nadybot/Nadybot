<template>
  <img
    :src="require(`../assets/font-awesome-icons/${icon}.svg`).default"
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
});
</script>
