<template>
  <button
    type="button"
    class="btn btn-tri-toggle"
    data-toggle="button"
    :class="modelValue"
    @click="toggleState"
    autocomplete="off"
  >
    <div class="handle"></div>
  </button>
</template>

<style lang="scss" scoped>
// Colors
$brand-all: #26cc26;
$brand-some: #ff8300;
$gray: #6b7381;
$gray-light: lighten($gray, 15%);
$gray-lighter: lighten($gray, 30%);

// Button Colors
$btn-default-color: $gray;
$btn-default-bg: $gray-lighter;

// Toggle Sizes
$toggle-default-size: 1.5rem;

// Mixin for Switch Colors
// Variables: $color, $bg, $active-bg
@mixin toggle-color(
  $color: $btn-default-color,
  $bg: $btn-default-bg,
  $all-bg: $brand-all,
  $some-bg: $brand-some
) {
  color: $color;
  background: $bg;
  &.all {
    background-color: $all-bg;
  }
  &.some {
    background-color: $some-bg;
  }
}

// Mixin for Default Switch Styles
// Variables: $size, $margin, $color, $bg, $active-bg, $font-size
@mixin toggle-mixin($size: $toggle-default-size) {
  // color: $color;
  // background: $bg;
  padding: 0;
  position: relative;
  border: none;
  height: $size;
  width: $size * 2;
  border-radius: $size;

  &:focus,
  &.focus {
    &,
    &.active {
      outline: none;
    }
  }

  > .handle {
    position: absolute;
    top: ($size * 0.25) / 2;
    left: ($size * 0.25) / 2;
    width: $size * 0.75;
    height: $size * 0.75;
    border-radius: $size * 0.75;
    background: #fff;
    transition: left 0.25s;
  }
  &.all {
    transition: background-color 0.25s;
    > .handle {
      left: $size + (($size * 0.25) / 2);
      transition: left 0.25s;
    }
  }
  &.some {
    > .handle {
      left: 32%;
    }
  }
}

// Apply Mixin to different sizes & colors
.btn-tri-toggle {
  @include toggle-mixin;
  @include toggle-color;

  &.btn-lg {
    @include toggle-mixin($size: 2.5rem);
  }

  &.btn-xs {
    @include toggle-mixin($size: 1rem);
  }
}
</style>

<script lang="ts">
import { defineComponent } from "vue";
export default defineComponent({
  name: "TriToggle",

  methods: {
    toggleState: function (): void {
      let next_toggle;
      if (this.modelValue == "some") {
        next_toggle = true;
      } else if (this.modelValue == "all") {
        next_toggle = false;
      } else {
        next_toggle = true;
      }

      console.log(`next value is ${next_toggle}`);
      this.$emit("update:modelValue", next_toggle);
      this.$emit("click");
    },
  },

  props: {
    modelValue: {
      type: String,
      required: false,
      default: "none",
    },
  },
});
</script>
