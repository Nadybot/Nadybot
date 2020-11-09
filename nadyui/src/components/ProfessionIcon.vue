<template>
  <img :src="require(`@/assets/${profession}.svg`)" />
</template>

<style lang="scss" scoped>
img {
  height: 25px;
  width: 25px;
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
