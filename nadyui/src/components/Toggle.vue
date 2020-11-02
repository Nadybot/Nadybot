<template>
  <button
    type="button"
    class="btn btn-toggle"
    data-toggle="button"
    :aria-pressed="toggled"
    :class="{ active: toggled === true }"
    @click="toggleState"
    autocomplete="off"
  >
    <div class="handle"></div>
  </button>
</template>

<style lang="scss" scoped>
// Colors
$brand-primary: #26cc26;
$brand-secondary: #ff8300;
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
  $active-bg: $brand-primary
) {
  color: $color;
  background: $bg;
  &.active {
    background-color: $active-bg;
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
  &.active {
    transition: background-color 0.25s;
    > .handle {
      left: $size + (($size * 0.25) / 2);
      transition: left 0.25s;
    }
  }
}

// Apply Mixin to different sizes & colors
.btn-toggle {
  @include toggle-mixin;
  @include toggle-color;

  &.btn-lg {
    @include toggle-mixin($size: 2.5rem);
  }

  &.btn-xs {
    @include toggle-mixin($size: 1rem);
  }

  &.btn-secondary {
    @include toggle-color($active-bg: $brand-secondary);
  }
}
</style>

<script lang="ts">
import { defineComponent } from "vue";
export default defineComponent({
  name: "Toggle",

  data: function () {
    return {
      toggled: true,
    };
  },

  methods: {
    toggleState: function (): void {
      this.toggled = !this.toggled;
    },
  },

  created(): void {
    this.toggled = this.initial;
  },

  props: {
    initial: {
      type: Boolean,
      required: false,
      default: true,
    },
  },
});
</script>
