import { Store } from "vuex";

const protocol = window.location.protocol == "https:" ? "wss" : "ws";
const client = new WebSocket(`${protocol}://${window.location.host}/api`);

export default function createWebSocketPlugin() {
  return function (store: Store<unknown>): void {
    //subscribe to events
    client.onopen = function (_e: Event) {
      client.send('{"command": "subscribe", "data": {"events": ["*"]}}');
      store.dispatch("websocketOpen");
    };

    client.onmessage = function (e: MessageEvent<string>) {
      store.dispatch("websocketData", e.data);
    };
  };
}
