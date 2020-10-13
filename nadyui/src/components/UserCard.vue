<template>
  <div class="card" style="width: 12rem">
    <img class="card-img-top" :src="imageUrl" alt="Card image cap" />
    <div class="card-body">
      <h5 class="card-title">{{ title }}</h5>
      <p class="card-text">
        {{ content }}
      </p>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

export default defineComponent({
  name: "UserCard",

  props: {
    imageUrl: {
      type: String,
      required: true,
    },
    title: {
      type: String,
      required: true,
    },
    content: {
      content: String,
      required: true,
    },
  },
});
</script>
