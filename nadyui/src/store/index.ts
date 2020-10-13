import { createStore } from "vuex";

import { getOnlineMembers } from "@/nadybot/http";

import socket from "./plugins/socket";
import { OnlinePlayers } from "@/nadybot/types";

const emptyPlayers: OnlinePlayers = {
  org: [],
  private_channel: [],
};

export default createStore({
  state: {
    users: emptyPlayers,
  },
  mutations: {
    async loadOnlineUsers(state): Promise<void> {
      const users = await getOnlineMembers();
      state.users = users;
    },
  },
  actions: {
    websocketOpen(): void {
      console.log("websocket is open");
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
