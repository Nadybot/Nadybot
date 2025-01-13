<template>
  <li>
    <router-link :to="$props.path"
      ><fa :icon="$props.icon" :size="20" /> {{ name }}</router-link
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
    text-decoration: none;

    &:active,
    &:hover,
    &:focus {
      outline: none;
    }
  }

  img {
    vertical-align: bottom;
    margin-right: 3px;
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
