<template>
  <ul class="list-group" id="chat-list">
    <template v-for="msg in chat_messages" :key="msg">
      <li class="list-group-item org-text">
        <template
          v-if="
            msg.path && msg.path.filter((p) => p.renderAs !== null).length > 0
          "
          ><span
            v-html="
              msg.path
                .filter((p) => p.renderAs !== null)
                .map((p) => {
                  if (!p.color) {
                    return `[${p.renderAs}]`;
                  } else {
                    return `<span
            style='color: #${p.color}'
            >[${p.renderAs}]</span
          >`;
                  }
                })
                .join(' ')
            "
          ></span
          >&nbsp;</template
        ><span v-if="msg.sender" class="link">{{ msg.sender }}:&nbsp;</span
        ><span :style="msg.color ? 'color: #' + msg.color : ''"
          ><message
            :content="msg.message"
            @run-command="runCommand($event)"
          ></message
        ></span>
      </li>
    </template>
  </ul>

  <input
    type="text"
    class="form-control input-fixed-bottom"
    id="chat-input-field"
    placeholder="Enter a message"
    aria-label="Enter a message"
    @keyup="maybeSend"
    v-model="inputText"
  />
</template>

<style lang="scss">
$org-color: #00f700;
$link-color: #5798f9;

#chat-channel {
  max-width: 5vw;
  background-color: #a0a3a6;
}

#chat-list {
  height: 90vh;
  overflow-y: scroll;
}

.org-text {
  color: $org-color !important;
  padding: 0.25rem 0.5rem;
}

.link {
  color: $link-color;
}
</style>

<script lang="ts">
import { mapMutations, mapState } from "vuex";
import { defineComponent } from "vue";
import { replaceItemRefs } from "@/nadybot/message";
import { sendMessage, getSystemInformation } from "@/nadybot/http";
import { SystemInformation } from "@/nadybot/types/stats";
import { requestPermission } from "@/utils/notify";

interface ChatData {
  inputText: string;
  historyIdx: number;
  systemInformation: SystemInformation | null;
}

export default defineComponent({
  name: "Chat",

  data(): ChatData {
    return {
      inputText: "",
      historyIdx: 0,
      systemInformation: null,
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
    chat_messages: {
      handler: function (): void {
        setTimeout(this.scrollOutputDown, 100);
      },
      deep: true,
    },
  },

  methods: {
    focusInput: function (): void {
      const elem = document.getElementById("chat-input-field");
      if (elem) {
        elem.focus();
      }
    },
    maybeSend: async function (e: KeyboardEvent): Promise<void> {
      if (e.key == "Enter") {
        await this.sendChatMessage(this.inputText);
        this.scrollOutputDown();
        this.inputText = "";
      } else if (
        e.key == "ArrowUp" &&
        this.historyIdx < this.chat_history.length
      ) {
        // Go back in history
        this.inputText =
          this.chat_history[this.chat_history.length - this.historyIdx - 1];
        this.historyIdx++;
      } else if (e.key == "ArrowDown" && this.historyIdx > 0) {
        // Go forward in history
        this.inputText =
          this.chat_history[this.chat_history.length - this.historyIdx + 1];
        this.historyIdx--;
      }
    },
    scrollOutputDown: function (): void {
      const scroll = document.getElementById("chat-list");
      if (scroll) {
        scroll.scrollTop = scroll.scrollHeight;
      }
    },
    runCommand: async function (text: string) {
      if (this.systemInformation) {
        text = text.replace(
          `tell ${this.systemInformation.basic.bot_name}`,
          ""
        );
      }
      text = text.trim();
      if (!text.startsWith("!")) {
        text = `!${text}`;
      }
      await this.sendChatMessage(text);
    },
    sendChatMessage: async function (text: string): Promise<void> {
      this.addChatHistoryEntry(text);
      this.historyIdx = 0;
      const formatted = replaceItemRefs(text);
      await sendMessage(formatted);
    },
    ...mapMutations(["addChatHistoryEntry"]),
  },

  async created(): Promise<void> {
    this.systemInformation = await getSystemInformation();
    await requestPermission();
  },

  computed: mapState(["chat_messages", "chat_history"]),
});
</script>
