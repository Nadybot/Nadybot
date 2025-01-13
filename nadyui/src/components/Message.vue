<template>
  <ao-message
    v-if="text"
    :content="text"
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

  computed: {
    popups: function (): Record<string, Element> {
      const messageNode = this.content.children[0];
      const dataNode = messageNode.lastElementChild;
      if (dataNode && dataNode.nodeName == "data") {
        const popups: { [id: string]: Element } = {};
        const amt = dataNode.children.length;
        for (let i = 0; i < amt; i++) {
          const node = dataNode.children[i];
          const id = node.getAttribute("id");
          if (id) {
            popups[id] = node;
          }
        }
        return popups;
      }
      return {};
    },
    text: function (): Node | null {
      const messageNode = this.content.firstChild;
      if (messageNode) {
        const textNode = messageNode.firstChild;
        if (textNode && textNode.nodeName == "text") {
          return textNode;
        }
      }
      return null;
    },
  },

  emits: ["run-command"],
});
</script>
