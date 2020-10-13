import { createStore } from "vuex";

import { getOnlineMembers } from "@/nadybot/http";

import socket from "./plugins/socket";

export default createStore({
  state: {
    users: [],
  },
  mutations: {
    loadOnlineUsers(): void {
      console.log(getOnlineMembers());
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
