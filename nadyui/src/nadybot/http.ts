import axios from "axios";
import { OnlinePlayers, onlinePlayersDecoder } from "./types";

export async function getOnlineMembers(): Promise<OnlinePlayers> {
  const response = await axios.get("/api/online");
  return await onlinePlayersDecoder.decodePromise(response.data);
}
