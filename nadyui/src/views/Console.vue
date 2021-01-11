<template>
  <ul class="list-group" id="console-list">
    <li
      v-for="msg in console_messages"
      :key="msg"
      class="list-group-item"
      :class="{ 'list-group-item-dark': msg.from_user }"
    >
      <message
        :content="msg.message"
        @run-command="runCommand($event)"
      ></message>
    </li>
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
</style>

<script lang="ts">
import { mapActions, mapMutations, mapState } from "vuex";
import { defineComponent } from "vue";

interface ConsoleData {
  inputText: string;
  historyIdx: number;
}

export default defineComponent({
  name: "Console",

  data(): ConsoleData {
    return {
      inputText: "",
      historyIdx: 0,
    };
  },

  // Darkmode per view hack
  beforeCreate(): void {
    document.body.className = "dark";
  },

  beforeUnmount(): void {
    document.body.className = "";
  },

  mounted(): void {
    this.focusInput();
  },

  watch: {
    console_messages: {
      handler: function (): void {
        setTimeout(this.scrollOutputDown, 100);
      },
      deep: true,
    },
  },

  methods: {
    focusInput: function (): void {
      const elem = document.getElementById("command-input-field");
      if (elem) {
        elem.focus();
      }
    },
    maybeSend: async function (e: KeyboardEvent): Promise<void> {
      if (e.key == "Enter") {
        await this.runCommand(this.inputText);
        this.scrollOutputDown();
        this.inputText = "";
      } else if (
        e.key == "ArrowUp" &&
        this.historyIdx < this.console_history.length
      ) {
        // Go back in history
        this.inputText = this.console_history[
          this.console_history.length - this.historyIdx - 1
        ];
        this.historyIdx++;
      } else if (e.key == "ArrowDown" && this.historyIdx > 0) {
        // Go forward in history
        this.inputText = this.console_history[
          this.console_history.length - this.historyIdx + 1
        ];
        this.historyIdx--;
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
      this.addConsoleHistoryEntry(command);
      this.historyIdx = 0;
      await this.executeCommand(command);
    },
    ...mapActions(["executeCommand"]),
    ...mapMutations(["addConsoleHistoryEntry"]),
  },

  computed: mapState(["console_messages", "console_history"]),
});
</script>
