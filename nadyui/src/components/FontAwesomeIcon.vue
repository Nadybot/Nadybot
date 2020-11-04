<template>
  <img :src="path" :height="size" :width="size" class="inverted" />
</template>

<style lang="scss">
.inverted {
  filter: invert(100%);
}
</style>

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
    class: String,
  },

  computed: {
    path(): string {
      return require(`../assets/font-awesome-icons/${this.icon}.svg`);
    },
  },
});
</script>
