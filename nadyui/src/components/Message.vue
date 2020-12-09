<template>
  <ao-message
    :content="content"
    @open-popup="active_popup = $event"
    @run-command="runCommand($event)"
  ></ao-message>

  <div
    v-for="(popup, id) in popups"
    :key="id"
    class="modal fade d-block"
    :class="{ show: active_popup == id }"
    tabindex="-1"
  >
    <div class="modal-dialog model-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <ao-message
              :content="popup.firstChild.firstChild"
              :format-for-title="true"
            ></ao-message>
          </h5>
          <button
            type="button"
            class="btn-close"
            @click="active_popup = null"
            aria-label="Close"
          ></button>
        </div>
        <div class="modal-body">
          <ao-message
            :content="removeFirstChild(popup)"
            @run-command="runCommand($event)"
          ></ao-message>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.fade:not(.show) {
  z-index: -1;
}

.modal {
  background: rgba(1, 1, 1, 0.8);
  backdrop-filter: blur(5px);

  .modal-dialog {
    min-width: 50vw;

    .modal-header {
      position: sticky;
      top: 0;
      background-color: #333;
      z-index: 100000;
      .btn-close {
        filter: invert(100%);
        opacity: 1;
      }
    }

    .modal-content {
      border: 1.5px solid #fff;
      max-height: 94vh;
      background-color: #333;
      color: #89d2e8;

      .modal-body {
        overflow-y: scroll;
      }
    }
  }
}
</style>

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
