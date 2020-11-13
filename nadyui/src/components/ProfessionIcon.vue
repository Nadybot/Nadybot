<template>
  <img :src="require(`@/assets/${profession}.svg`)" />
</template>

<style lang="scss" scoped>
img {
  height: 20px;
  vertical-align: text-bottom;
}
</style>

<script lang="ts">
import { defineComponent } from "vue";

export default defineComponent({
  name: "ProfessionIcon",

  props: {
    profession: {
      type: String,
      required: true,
    },
  },
});
</script>
