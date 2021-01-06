import { JsonDecoder } from "ts.data.json";

export interface MessageIncoming {
  message: string;
  popups: Record<string, string>;
  from_user: boolean;
}

export interface ChatMessageIncoming {
  channel: string;
  message: string;
  structMessage: {
    message: string;
    popups: Record<string, string>;
  };
  sender: string;
}

export interface ChatMessage {
  channel: string;
  message: XMLDocument;
  popups: Record<string, XMLDocument>;
  sender: string;
}

export interface Message {
  message: XMLDocument;
  popups: Record<string, XMLDocument>;
  from_user: boolean;
}

export interface CommandReply {
  class: string;
  msgs: Array<MessageIncoming>;
  type: string;
  uuid: string;
}

const MessageDecoderMapping = {
  message: JsonDecoder.string,
  popups: JsonDecoder.dictionary(JsonDecoder.string, "popups"),
  from_user: JsonDecoder.failover(false, JsonDecoder.boolean),
};

const messageDecoder = JsonDecoder.objectStrict<MessageIncoming>(
  MessageDecoderMapping,
  "Message"
);

const CommandReplyDecoderMapping = {
  class: JsonDecoder.string,
  msgs: JsonDecoder.array(messageDecoder, "MessageArray"),
  type: JsonDecoder.string,
  uuid: JsonDecoder.string,
};

export const commandReplyDecoder = JsonDecoder.objectStrict<CommandReply>(
  CommandReplyDecoderMapping,
  "CommandReply"
);
