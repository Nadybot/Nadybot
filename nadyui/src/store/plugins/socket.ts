import { Store } from "vuex";

export default function createWebSocketPlugin() {
  return function (store: Store<unknown>): void {
    const protocol = window.location.protocol == "https:" ? "wss" : "ws";
    const client = new WebSocket(
      `${protocol}://${window.location.host}/events`
    );

    //subscribe to events
    client.onopen = function (_e: Event) {
      client.send('{"command": "subscribe", "data": {"events": ["*"]}}');
      store.dispatch("websocketOpen");
    };

    client.onmessage = function (e: MessageEvent<string>) {
      store.dispatch("websocketData", e.data);
    };

    client.onclose = function (e: CloseEvent) {
      store.dispatch("websocketClose", e.code);
    };
  };
}
