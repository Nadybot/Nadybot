<template>
  <input
    class="form-control form-control-sm"
    :class="{ 'has-options': options.length > 0 }"
    :type="type"
    v-model="value"
    @click="updateOptionPosition($event.target)"
    @change="options_shown = false"
    @blur="options_shown = false"
  />

  <ul
    class="list-group option-list"
    v-if="options.length > 0 && options_shown"
    :style="{
      width: `${option_list_width}px`,
      top: `calc(${option_list_top}px + 0.25rem)`,
    }"
  >
    <li
      class="list-group-item list-group-item-action"
      v-for="option in options"
      :key="option.name"
      @mousedown="value = option.value"
    >
      <div class="fw-bold">{{ option.name }}</div>
      {{ option.value }}
    </li>
  </ul>
</template>

<style lang="scss">
.has-options {
  background-image: url("data:image/svg+xml,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 16 16%27%3e%3cpath fill=%27none%27 stroke=%27%23343a40%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27 stroke-width=%272%27 d=%27m2 5 6 6 6-6%27/%3e%3c/svg%3e");
  background-repeat: no-repeat;
  background-position: right 0.75rem center;
  background-size: 16px 12px;
}

.option-list {
  font-size: 0.875rem;
  z-index: 999;
  position: absolute;

  li {
    text-align: left;

    &:hover {
      cursor: pointer;
    }
  }
}
</style>

<script lang="ts">
import { SettingOption } from "@/nadybot/types/settings";
import { defineComponent } from "vue";

export default defineComponent({
  name: "InputOptions",

  data() {
    return {
      options_shown: false,
      option_list_width: 0,
      option_list_top: 0,
    };
  },

  props: {
    type: {
      type: String,
      required: true,
    },
    options: {
      type: Array<SettingOption>,
      default: [],
      required: true,
    },
    modelValue: {
      required: true,
    },
  },

  computed: {
    value: {
      get() {
        return this.modelValue;
      },
      set(value: string | number | null) {
        this.$emit("update:modelValue", value);

        if (value != this.modelValue) {
          this.$emit("change");
        }
      },
    },
  },

  methods: {
    updateOptionPosition(setting: Element): void {
      const rect = setting.getBoundingClientRect();
      if (!setting.parentElement?.parentElement) return;
      const parentRect =
        setting.parentElement.parentElement.getBoundingClientRect();
      this.option_list_top = rect.bottom - parentRect.top;
      this.option_list_width = rect.width;
      this.options_shown = true;
    },
  },

  emits: ["change", "update:modelValue"],
});
</script>
