<note>
From https://github.com/cdmoro/bootstrap-vue-3/blob/main/src/components/BPopover.vue
</note>

<template>
  <div
    :id="id"
    ref="element"
    class="popover b-popover"
    role="tooltip"
    tabindex="-1"
  >
    <div ref="titleRef">
      <slot name="title">
        {{ title }}
      </slot>
    </div>
    <div ref="contentRef">
      <slot>
        {{ content }}
      </slot>
    </div>
  </div>
</template>

<script lang="ts">
import Popover from "bootstrap/js/dist/popover";
import { defineComponent, onMounted, onUnmounted, PropType, ref } from "vue";

export default defineComponent({
  name: "Popover",
  props: {
    content: { type: String },
    id: { type: String },
    noninteractive: { type: Boolean, default: false },
    placement: {
      type: String as PropType<Popover.Options["placement"]>,
      default: "right",
    },
    target: { type: String, required: true },
    title: { type: String },
    triggers: {
      type: String as PropType<Popover.Options["trigger"]>,
      default: "click",
    },
  },
  setup(props) {
    const element = ref<HTMLElement>();
    const target = ref<HTMLElement>();
    const instance = ref<Popover>();
    const titleRef = ref<HTMLElement>();
    const contentRef = ref<HTMLElement>();
    onMounted(() => {
      instance.value = new Popover(`#${props.target}`, {
        //container: "body",
        trigger: props.triggers,
        placement: props.placement,
        title: titleRef.value || "",
        content: contentRef.value || "",
        html: true,
      });
      if (document.getElementById(props.target)) {
        target.value = document.getElementById(props.target) as HTMLElement;
      }
      element.value?.parentNode?.removeChild(element.value);
    });
    onUnmounted(() => {
      if (instance.value) {
        //instance.value.hide();
        //instance.value.disable();
        instance.value.dispose();
      }
    });
    return {
      element,
      titleRef,
      contentRef,
    };
  },
});
</script>
