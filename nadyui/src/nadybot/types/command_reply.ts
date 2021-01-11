import { JsonDecoder } from "ts.data.json";

export interface MessageIncoming {
  message: string;
  popups: Record<string, string>;
  from_user: boolean;
}

export interface ChatMessageIncoming {
  channel: string;
  message: string;
  sender: string;
}

export interface ChatMessage {
  channel: string;
  message: XMLDocument;
  sender: string;
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
