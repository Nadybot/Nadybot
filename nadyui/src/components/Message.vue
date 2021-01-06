<template>
  <ao-message
    v-if="content"
    :content="content"
    @open-popup="active_popup = $event"
    @run-command="runCommand($event)"
  ></ao-message>

  <popup
    v-for="(popup, id) in popups"
    :content="popup"
    :key="id"
    :show="active_popup == id"
    @close="active_popup = null"
    @run-command="runCommand($event)"
  >
  </popup>
</template>

<script lang="ts">
import { defineComponent } from "vue";

interface Data {
  active_popup: XMLDocument | null;
}

export default defineComponent({
  name: "Message",

  data(): Data {
    return {
      active_popup: null,
    };
  },

  mounted(): void {
    document.addEventListener("keydown", this.closePopupIfEscape);
  },

  unmounted(): void {
    document.removeEventListener("keydown", this.closePopupIfEscape);
  },

  props: {
    content: {
      type: XMLDocument,
      required: true,
    },
    popups: {
      type: Object,
      required: true,
    },
  },

  methods: {
    removeFirstChild: function (node: Node): Node {
      const newNode = node.cloneNode(true);
      if (newNode.firstChild && newNode.firstChild.firstChild) {
        newNode.firstChild.removeChild(newNode.firstChild.firstChild);
      }
      return newNode;
    },
    closePopupIfEscape: function (e: KeyboardEvent): void {
      if (e.key == "Escape") {
        this.active_popup = null;
      }
    },
    runCommand: function (e: string): void {
      this.active_popup = null;
      this.$emit("run-command", e);
    },
  },

  emits: ["run-command"],
});
</script>
