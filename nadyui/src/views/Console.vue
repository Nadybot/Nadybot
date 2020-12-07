<template>
  <ul class="list-group" id="console-list">
    <li
      v-for="(msg, msgid) in messages"
      :key="msgid"
      class="list-group-item"
      :class="{ 'list-group-item-dark': msg.from_user }"
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
              @click="closeActivePopup()"
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
    id="command-input-field"
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

.modal-dialog {
  min-width: 50vw;

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
}

body.dark {
  &,
  .list-group-item:not(.list-group-item-dark),
  .modal-dialog .modal-content {
    background-color: #333;
    color: #89d2e8;
  }

  .btn-close {
    filter: invert(100%);
    opacity: 1;
  }

  .form-control {
    background-color: #c6c8ca;
  }
}
</style>

<script lang="ts">
import { mapGetters, mapActions } from "vuex";
import { defineComponent } from "vue";

import { parseXml, formatXmlDocument } from "@/nadybot/message";

interface ConsoleData {
  inputText: string;
  commandHistory: Array<string>;
  historyIdx: number;
  activePopup: string;
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
      commandHistory: [],
      historyIdx: 0,
      activePopup: "",
    };
  },

  // Darkmode per view hack
  beforeCreate(): void {
    document.body.className = "dark";
  },

  beforeUnmount(): void {
    document.body.className = "";
  },

  created(): void {
    window.togglePopup = this.togglePopup.bind(this);
    window.executeCommand = this.runCommand.bind(this);
  },

  mounted(): void {
    const elem = document.getElementById("command-input-field");
    if (elem) {
      elem.focus();
    }
    document.addEventListener("keydown", this.closePopupIfEscape);
  },

  methods: {
    maybeSend: async function (e: KeyboardEvent): Promise<void> {
      if (e.key == "Enter") {
        await this.runCommand(this.inputText);
        this.scrollOutputDown();
        this.inputText = "";
      } else if (
        e.key == "ArrowUp" &&
        this.historyIdx < this.commandHistory.length
      ) {
        // Go back in history
        this.inputText = this.commandHistory[
          this.commandHistory.length - this.historyIdx - 1
        ];
        this.historyIdx++;
      } else if (e.key == "ArrowDown" && this.historyIdx > 0) {
        // Go forward in history
        this.inputText = this.commandHistory[
          this.commandHistory.length - this.historyIdx + 1
        ];
        this.historyIdx--;
      }
    },
    formatMsg: function (id: number, msg: string): string {
      const parsed = parseXml(msg);
      const real = formatXmlDocument(id, parsed);
      return real;
    },
    togglePopup: function (msgId: number, popupId: number): void {
      const popup = `popup-${msgId}-${popupId}`;
      const elem = document.getElementById(popup);
      if (elem) {
        this.activePopup = popup;
        elem.classList.add("show");
      }
    },
    closeActivePopup: function (): void {
      const elem = document.getElementById(this.activePopup);
      if (elem) {
        this.activePopup = "";
        elem.classList.remove("show");
      }
    },
    closePopupIfEscape: function (e: KeyboardEvent): void {
      if (e.key == "Escape") {
        this.closeActivePopup();
      }
    },
    scrollOutputDown: function (): void {
      const scroll = document.getElementById("console-list");
      if (scroll) {
        scroll.scrollTop = scroll.scrollHeight;
      }
    },
    runCommand: async function (command: string): Promise<void> {
      // Soft wrapper for executeCommand with history integration
      this.commandHistory.push(command);
      this.historyIdx = 0;
      this.closeActivePopup();
      this.scrollOutputDown();
      await this.executeCommand(command);
    },
    ...mapActions(["executeCommand"]),
  },

  computed: {
    ...mapGetters(["messages"]),
  },
});
</script>
