import { Store } from "vuex";

export default function createWebSocketPlugin() {
  return function (store: Store<unknown>): void {
    const protocol = window.location.protocol == "https:" ? "wss" : "ws";
    const client = new WebSocket(
      `${protocol}://${window.location.host}/events`
    );

    //subscribe to events
    client.onopen = function (_e: Event) {
      client.send(
        '{"command": "subscribe", "data": {"events": ["offline(*)", "online(*)", "cmdreply", "chat(web)"]}}'
      );
      store.dispatch("websocketOpen");
    };

    client.onmessage = async function (e: MessageEvent<string>) {
      const data = JSON.parse(e.data);
      if (data.command == "event") {
        store.dispatch(data.data.class, data.data);
      } else if (data.command == "uuid") {
        store.dispatch("websocketUuidUpdate", data.data);
      }
    };

    client.onclose = function (e: CloseEvent) {
      store.dispatch("websocketClose", e.code);
    };
  };
}
