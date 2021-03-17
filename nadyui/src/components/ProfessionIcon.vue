<template>
  <img :src="require(`@/assets/${profession}.svg`).default" class="prof-icon" />
</template>

<style lang="scss">
.prof-icon {
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
