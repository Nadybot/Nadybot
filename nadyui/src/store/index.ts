import { createStore } from "vuex";

import { getOnlineMembers } from "@/nadybot/http";

import socket from "./plugins/socket";
import { OnlinePlayers, OnlinePlayer } from "@/nadybot/types";

const emptyPlayers: OnlinePlayers = {
  org: [],
  private_channel: [],
};

export default createStore({
  state: {
    users: emptyPlayers,
  },
  getters: {
    allUsers(state): Array<OnlinePlayer> {
      return state.users.org.concat(state.users.private_channel);
    },
  },
  mutations: {
    async loadOnlineUsers(state): Promise<void> {
      const users = await getOnlineMembers();
      state.users = users;
      console.log(`Successfully loaded online members: ${users.org.length}`);
    },
  },
  actions: {
    websocketOpen(context): void {
      console.log("websocket is open");
      context.commit("loadOnlineUsers");
    },
    websocketData(_, data: string): void {
      console.log(`got data: ${data}`);
    },
    websocketClose(_, closeCode: number): void {
      console.log(`websocket closed with code ${closeCode}`);
    },
  },
  modules: {},
  plugins: [socket()],
});
