import { createStore } from "vuex";
import router from "@/router";

import socket from "./plugins/socket";
import {
  executeCommand,
  getOnlineMembers,
  getSystemInformation,
} from "@/nadybot/http";
import { OnlinePlayers, OnlinePlayer } from "@/nadybot/types/player";
import {
  CommandReply,
  Message,
  ChatMessageIncoming,
  ChatMessage,
} from "@/nadybot/types/command_reply";
import { parseXml, replaceItemRefs } from "@/nadybot/message";
import { notify } from "@/utils/notify";
import { SystemInformation } from "@/nadybot/types/stats";

const emptyPlayers: OnlinePlayers = {
  org: [],
  private_channel: [],
};

interface State {
  users: OnlinePlayers;
  users_failed: boolean;
  uuid: string;
  console_messages: Array<Message>;
  chat_messages: Array<ChatMessage>;
  console_history: Array<string>;
  chat_history: Array<string>;
  system_information: SystemInformation | null;
}

const initialState: State = {
  users: emptyPlayers,
  users_failed: false,
  uuid: "",
  console_messages: [],
  chat_messages: [],
  console_history: [],
  chat_history: [],
  system_information: null,
};

export default createStore({
  state: initialState,
  getters: {
    allUsers(state): Array<OnlinePlayer> {
      return state.users.org.concat(state.users.private_channel);
    },
  },
  mutations: {
    async loadOnlineUsers(state): Promise<void> {
      try {
        const users = await getOnlineMembers();
        state.users = users;
        state.users_failed = false;
        console.log(
          `Successfully loaded online members: ${
            users.org.length + users.private_channel.length
          }`
        );
      } catch (_e) {
        const e: Error = _e;
        console.log(`Fetching users failed: ${e.message}`);
        state.users_failed = true;
      }
    },
    addOnlineOrgUser(state, player: OnlinePlayer): void {
      const old_item = state.users.org.find((p) => p.name == player.name);
      if (!old_item) {
        state.users.org.push(player);
      }
    },
    addOnlinePrivUser(state, player: OnlinePlayer): void {
      const old_item = state.users.private_channel.find(
        (p) => p.name == player.name
      );
      if (!old_item) {
        state.users.private_channel.push(player);
      }
    },
    delOnlineOrgUser(state, player_name: string): void {
      state.users.org = state.users.org.filter(
        (player) => player.name != player_name
      );
    },
    delOnlinePrivUser(state, player_name: string): void {
      state.users.private_channel = state.users.private_channel.filter(
        (player) => player.name != player_name
      );
    },
    addConsoleHistoryEntry(state, entry: string): void {
      state.console_history.push(entry);
    },
    addChatHistoryEntry(state, entry: string): void {
      state.chat_history.push(entry);
    },
  },
  actions: {
    websocketOpen(context): void {
      context.commit("loadOnlineUsers");
    },
    websocketClose(_, closeCode: number): void {
      console.log(`Websocket closed with code ${closeCode}`);
    },
    async websocketUuidUpdate(context, uuid: string): Promise<void> {
      context.state.uuid = uuid;
      context.state.system_information = await getSystemInformation();
      console.log(
        "Successfully set UUID for outgoing commands and fetched system information"
      );
    },
    OnlineEvent(context, data: Record<string, unknown>): void {
      const player = data.player as OnlinePlayer;
      const channel = data.channel as string;
      if (channel == "org") {
        context.commit("addOnlineOrgUser", player);
      } else {
        context.commit("addOnlinePrivUser", player);
      }
    },
    OfflineEvent(context, data: Record<string, unknown>): void {
      const player_name = data.player as string;
      const channel = data.channel as string;
      if (channel == "org") {
        context.commit("delOnlineOrgUser", player_name);
      } else {
        context.commit("delOnlinePrivUser", player_name);
      }
    },
    CommandReplyEvent(context, data: CommandReply): void {
      data.msgs.forEach(function (msg) {
        const new_message: Message = {
          message: parseXml(msg),
          from_user: false,
        };
        context.state.console_messages.push(new_message);
      });
    },
    async AOChatEvent(context, msg: ChatMessageIncoming): Promise<void> {
      const xml = parseXml(msg.message);
      // Display the <text> note content as notification
      // if chat is not currently focused
      if (
        (!window.document.hasFocus() ||
          router.currentRoute.value.name != "Chat") &&
        xml.firstElementChild &&
        xml.firstElementChild.firstElementChild &&
        xml.firstElementChild.firstElementChild.nodeName == "text" &&
        xml.firstElementChild.firstElementChild.textContent &&
        this.state.system_information &&
        (!this.state.system_information.basic.org ||
          this.state.system_information.basic.bot_name != msg.channel)
      ) {
        if (msg.sender) {
          await notify(
            `Message from ${msg.sender}`,
            xml.firstElementChild.firstElementChild.textContent
          );
        } else {
          await notify(
            "New Message",
            xml.firstElementChild.firstElementChild.textContent
          );
        }
      }
      const new_message: ChatMessage = {
        message: xml,
        path: msg.path,
        sender: msg.sender,
      };
      context.state.chat_messages.push(new_message);
    },
    async executeCommand(context, command: string): Promise<void> {
      const message: Message = {
        message: parseXml(
          `<message><text>${command
            .replace("<", "&lt;")
            .replace(">", "&gt;")}</text></message>`
        ),
        from_user: true,
      };
      context.state.console_messages.push(message);
      const formattedCommand = replaceItemRefs(command);
      await executeCommand(context.state.uuid, formattedCommand);
    },
  },
  modules: {},
  plugins: [socket()],
});
