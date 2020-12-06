<template>
  <ul class="list-group" id="console-list">
    <li
      v-for="(msg, msgid) in messages"
      :key="msgid"
      class="list-group-item"
      :class="{ 'list-group-item-secondary': msg.from_user }"
      v-html="formatMsg(msgid, msg.message)"
    ></li>

    <template v-for="(msg, msgid) in messages">
      <div
        v-for="(popup, id) in msg.popups"
        :key="id"
        class="modal fade d-block"
        :id="'popup-' + msgid + '-' + id"
        tabindex="-1"
      >
        <div class="modal-dialog model-dialog-scrollable">
          <div class="modal-content">
            <button
              type="button"
              class="btn-close"
              @click="closePopup(msgid, id)"
              aria-label="Close"
            ></button>
            <div class="modal-body" v-html="formatMsg(msgid, popup)"></div>
          </div>
        </div>
      </div>
    </template>
  </ul>

  <input
    type="text"
    class="form-control input-fixed-bottom"
    placeholder="Enter a command"
    aria-label="Enter a command"
    @keyup="maybeSend"
    v-model="inputText"
  />
</template>

<style lang="scss">
#console-list {
  height: 90vh;
  overflow-y: scroll;
}

.input-fixed-bottom {
  bottom: 1.8vh;
  position: fixed;
  max-width: 87.3vw;
}

.triggers-action {
  color: #0d6efd;
  text-decoration: underline;

  &:hover {
    color: #0a58ca;
    cursor: pointer;
  }
}

.fade:not(.show) {
  z-index: -1;
}

.modal-content {
  overflow-y: scroll;
  max-height: 94vh;

  .btn-close {
    padding: 0.5rem 0.5rem;
    right: 0.5rem;
    top: 0.5rem;
    position: sticky;
    z-index: 50000;
    margin-left: auto;
  }
}
</style>

<script lang="ts">
import { mapGetters, mapActions } from "vuex";
import { defineComponent } from "vue";

import { parseXml, formatXmlDocument } from "@/nadybot/message";

interface ConsoleData {
  inputText: string;
}

// https://stackoverflow.com/questions/12709074/how-do-you-explicitly-set-a-new-property-on-window-in-typescript
declare global {
  interface Window {
    togglePopup: (msgId: number, popupId: number) => void;
    executeCommand: (command: string) => void;
  }
}

export default defineComponent({
  name: "Console",

  data(): ConsoleData {
    return {
      inputText: "",
    };
  },

  created(): void {
    window.togglePopup = this.togglePopup.bind(this);
    window.executeCommand = this.executeCommand.bind(this);
  },

  methods: {
    maybeSend: async function (e: KeyboardEvent): Promise<void> {
      if (e.key == "Enter") {
        await this.executeCommand(this.inputText);
        const scroll = document.getElementById("console-list");
        if (scroll) {
          scroll.scrollTop = scroll.scrollHeight;
        }
        this.inputText = "";
      }
    },
    formatMsg: function (id: number, msg: string): string {
      const parsed = parseXml(msg);
      const real = formatXmlDocument(id, parsed);
      return real;
    },
    togglePopup: function (msgId: number, popupId: number): void {
      const elem = document.getElementById(`popup-${msgId}-${popupId}`);
      if (elem) {
        elem.classList.add("show");
      }
    },
    closePopup: function (msgId: number, popupId: number): void {
      const elem = document.getElementById(`popup-${msgId}-${popupId}`);
      if (elem) {
        elem.classList.remove("show");
      }
    },
    ...mapActions(["executeCommand"]),
  },

  computed: {
    ...mapGetters(["messages"]),
  },
});
</script>
