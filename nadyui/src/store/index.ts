import { createStore } from "vuex";

import socket from "./plugins/socket";
import { executeCommand, getOnlineMembers } from "@/nadybot/http";
import { OnlinePlayers, OnlinePlayer } from "@/nadybot/types/player";
import {
  CommandReply,
  Message,
  ChatMessageIncoming,
  ChatMessage,
} from "@/nadybot/types/command_reply";
import { parseXml, replaceItemRefs } from "@/nadybot/message";

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
}

const initialState: State = {
  users: emptyPlayers,
  users_failed: false,
  uuid: "",
  console_messages: [],
  chat_messages: [],
  console_history: [],
  chat_history: [],
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
      } catch {
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
    websocketUuidUpdate(context, uuid: string): void {
      context.state.uuid = uuid;
      console.log("Successfully set UUID for outgoing commands");
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
    AOChatEvent(context, msg: ChatMessageIncoming): void {
      const new_message: ChatMessage = {
        message: parseXml(msg.message),
        channel: msg.channel,
        sender: msg.sender,
      };
      context.state.chat_messages.push(new_message);
    },
    async executeCommand(context, command: string): Promise<void> {
      const message: Message = {
        message: parseXml(`<message><text>${command}</text></message>`),
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
