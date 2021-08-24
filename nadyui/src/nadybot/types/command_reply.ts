import { JsonDecoder } from "ts.data.json";

export interface MessageIncoming {
  message: string;
  popups: Record<string, string>;
  from_user: boolean;
}

export interface ChatMessagePath {
  name: string;
  label: string | null;
  type: string;
}

export interface ChatMessageIncoming {
  channel: string;
  path: Array<ChatMessagePath> | null;
  message: string;
  sender: string | null;
}

export interface ChatMessage {
  path: Array<ChatMessagePath> | null;
  message: XMLDocument;
  sender: string | null;
}

export interface Message {
  message: XMLDocument;
  from_user: boolean;
}

export interface CommandReply {
  class: string;
  msgs: Array<string>;
  type: string;
  uuid: string;
}

const CommandReplyDecoderMapping = {
  class: JsonDecoder.string,
  msgs: JsonDecoder.array(JsonDecoder.string, "MessageArray"),
  type: JsonDecoder.string,
  uuid: JsonDecoder.string,
};

export const commandReplyDecoder = JsonDecoder.objectStrict<CommandReply>(
  CommandReplyDecoderMapping,
  "CommandReply"
);
