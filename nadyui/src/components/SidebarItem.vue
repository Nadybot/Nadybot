<template>
  <li>
    <router-link :to="$props.path"
      ><fa :icon="$props.icon" /> {{ name }}</router-link
    >
  </li>
</template>

<style lang="scss" scoped>
li {
  background-color: transparent;
  position: relative;
  display: inline-block;
  width: 100%;
  line-height: 20px;

  &::before {
    background-color: #2574a9;
    position: absolute;
    content: "";
    height: 100%;
    left: 0;
    top: 0;
    transition: width 0.2s ease-in;
    width: 3px;
    z-index: -1;
  }

  &:hover::before {
    width: 100%;
  }

  a {
    padding: 10px 15px 10px 30px;
    display: block;
    color: #fff;

    &:active,
    &:hover,
    &:focus {
      text-decoration: none;
      outline: none;
    }
  }

  svg {
    margin-right: 10px;
  }
}
</style>

<script lang="ts">
import { defineComponent } from "vue";

export default defineComponent({
  name: "SidebarItem",

  props: {
    path: {
      type: String,
      required: true,
    },
    icon: {
      type: String,
      required: true,
    },
    name: {
      type: String,
      required: true,
    },
  },
});
</script>
