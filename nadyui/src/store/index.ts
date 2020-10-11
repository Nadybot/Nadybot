import { createStore } from "vuex";

import socket from "./plugins/socket";

export default createStore({
  state: {},
  mutations: {},
  actions: {
    websocketOpen(): void {
      console.log("websocket is open");
    },
    websocketData(_, data: string): void {
      console.log(`got data: ${data}`);
    },
  },
  modules: {},
  plugins: [socket()],
});
