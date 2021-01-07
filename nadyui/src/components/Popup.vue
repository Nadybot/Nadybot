<template>
  <div class="modal fade d-block" :class="{ show: show }" tabindex="-1">
    <div class="modal-dialog model-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <ao-message :content="title" :format-for-title="true"></ao-message>
          </h5>
          <button
            type="button"
            class="btn-close"
            @click="$emit('close')"
            aria-label="Close"
          ></button>
        </div>
        <div class="modal-body">
          <ao-message
            :content="content"
            @run-command="$emit('run-command', $event)"
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

    .modal-title span {
      color: #89d2e8 !important;
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

export default defineComponent({
  name: "Popup",

  props: {
    content: {
      type: XMLDocument,
      required: true,
    },
    show: {
      type: Boolean,
      required: true,
    },
  },

  computed: {
    title(): Node | null {
      if (this.content.firstChild && this.content.firstChild.firstChild) {
        const child = this.content.firstChild.removeChild(
          this.content.firstChild.firstChild
        );
        const nodes = Array.from(this.content.firstChild.childNodes);
        for (let i = 0; i < nodes.length; i++) {
          const node = nodes[i];
          if (node.nodeName == "br") {
            this.content.firstChild.removeChild(node);
          } else {
            break;
          }
        }
        return child;
      }
      return null;
    },
  },

  emits: ["run-command", "close"],
});
</script>
