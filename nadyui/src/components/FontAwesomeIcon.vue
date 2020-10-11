<template>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    :class="$props.class"
    :viewBox="`0 0 ${width} ${height}`"
    :style="style"
  >
    <path fill="currentColor" :d="svgPath" />
  </svg>
</template>

<script lang="ts">
import { defineComponent, computed } from "vue";
import {
  findIconDefinition,
  IconPrefix,
  IconName,
} from "@fortawesome/fontawesome-svg-core";

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
    type: {
      type: String,
      default: "fas",
      required: false,
    },
    class: String,
  },

  computed: {
    style(): string {
      return `max-width: ${this.size}px; max-height: ${this.size}px`;
    },
  },

  setup(props) {
    const definition = computed(() =>
      findIconDefinition({
        prefix: props.type as IconPrefix,
        iconName: props.icon as IconName,
      })
    );

    const width = computed(() => definition.value.icon[0]);
    const height = computed(() => definition.value.icon[1]);
    const svgPath = computed(() => definition.value.icon[4]);

    return { width, height, svgPath };
  },
});
</script>
