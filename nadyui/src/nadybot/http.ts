import axios, { AxiosResponse } from "axios";
import { OnlinePlayers, onlinePlayersDecoder } from "./types";

export async function getOnlineMembers(): Promise<OnlinePlayers> {
  const response = await axios.get("/api/online");
  console.log(response.request.response);
  const onlinePlayers = await onlinePlayersDecoder.decodePromise(
    response.request.response
  );
  return onlinePlayers;
}
