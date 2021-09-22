import { JsonDecoder } from "ts.data.json";

export enum DatabaseType {
  sqlite = "sqlite",
  mysql = "mysql",
  postgresql = "postgresql",
  mssql = "mssql",
}

export interface BasicSystemInformation {
  // Name of the bot character in AO
  readonly bot_name: string;
  // Name of the character running the bot, null if not set
  readonly superadmin: string | null;
  // Name of the org this bot is in or null if not in an org
  readonly org: string | null;
  // ID of the org this bot is in or null if not in an org
  readonly org_id: number | null;
  // Which Nadybot version are we running?
  readonly bot_version: string;
  // Which PHP version are we running?
  readonly php_version: string;
  // Which operating system/kernel are we running?
  readonly os: string;
  // Which database type (mysql/sqlite) are we using?
  readonly db_type: DatabaseType;
}

export interface MemoryInformation {
  // Current memory usage in bytes
  readonly current_usage: number;
  // Current memory usage in bytes including allocated system pages
  readonly current_usage_real: number;
  // Peak memory usage in bytes
  readonly peak_usage: number;
  // Peak memory usage in bytes including allocated system pages
  readonly peak_usage_real: number;
}

interface ProxyCapabilities {
  // Name of the proxy software
  readonly name: string | null;
  // Version of the proxy software
  readonly version: string | null;
  // Modes the proxy supports for sending messages
  readonly send_modes: Array<string>;
  // The mode the proxy will use when sending proxy-default
  readonly default_mode?: string | null;
  // Unix timestamp when the proxy was started
  readonly started_at?: number | null;
}

export interface MiscSystemInformation {
  // Is the bot using a chat proxy for mass messages or more than 1000 friends
  readonly using_chat_proxy: boolean;
  // Number of seconds since the bot was started
  readonly uptime: number;
  // If the proxy is used, this describes in detail what the proxy supports
  readonly proxy_capabilities?: ProxyCapabilities;
}

export interface ConfigStatistics {
  // Number of commands activated for use with /tell
  readonly active_tell_commands: number;
  // Number of commands activated for use in the private channel
  readonly active_priv_commands: number;
  // Number of commands activated for use in the org channel
  readonly active_org_commands: number;
  // Number of subcommands activated
  readonly active_subcommands: number;
  // Number of aliases
  readonly active_aliases: number;
  // Number of currently active events
  readonly active_events: number;
  // Number of active help texts for commands
  readonly active_help_commands: number;
}

export interface SystemStats {
  // How many characters are currently on the friendlist
  readonly buddy_list_size: number;
  // Maximum allowed characters for the friendlist
  readonly max_buddy_list_size: number;
  // How many people are currently on the bot's private channel
  readonly priv_channel_size: number;
  // How many people are in the bot's org? 0 if not in an org
  readonly org_size: number;
  // How many character infos are currently cached?
  readonly charinfo_cache_size: number;
  // How many messages are waiting to be sent?
  readonly chatqueue_length: number;
}

export interface ChannelInfo {
  // The name of the public channel
  readonly name: string;
  // The ID the game uses for this channel
  readonly id: number;
}

export interface SystemInformation {
  readonly basic: BasicSystemInformation;
  readonly memory: MemoryInformation;
  readonly misc: MiscSystemInformation;
  readonly config: ConfigStatistics;
  readonly stats: SystemStats;
  // Which channels is the bot listening to
  readonly channels: Array<ChannelInfo>;
}

const databaseTypeDecoder = JsonDecoder.enumeration<DatabaseType>(
  DatabaseType,
  "DatabaseType"
);

const basicSystemInformationDecoderMapping = {
  bot_name: JsonDecoder.string,
  superadmin: JsonDecoder.nullable(JsonDecoder.string),
  org: JsonDecoder.nullable(JsonDecoder.string),
  org_id: JsonDecoder.nullable(JsonDecoder.number),
  bot_version: JsonDecoder.string,
  php_version: JsonDecoder.string,
  os: JsonDecoder.string,
  db_type: databaseTypeDecoder,
};

const memoryInformationDecoderMapping = {
  current_usage: JsonDecoder.number,
  current_usage_real: JsonDecoder.number,
  peak_usage: JsonDecoder.number,
  peak_usage_real: JsonDecoder.number,
};

const proxyCapabilitiesDecoderMapping = {
  name: JsonDecoder.nullable(JsonDecoder.string),
  version: JsonDecoder.nullable(JsonDecoder.string),
  send_modes: JsonDecoder.array(JsonDecoder.string, "SendModeArray"),
  default_mode: JsonDecoder.optional(JsonDecoder.string),
  started_at: JsonDecoder.optional(JsonDecoder.number),
};

const proxyCapabilitiesDecoder = JsonDecoder.object<ProxyCapabilities>(
  proxyCapabilitiesDecoderMapping,
  "ProxyCapabilites",
  {
    default_mode: "default-mode",
    started_at: "started-at",
    send_modes: "send-modes",
  }
);

const miscSystemInformationDecoderMapping = {
  using_chat_proxy: JsonDecoder.boolean,
  uptime: JsonDecoder.number,
  proxy_capabilities: JsonDecoder.optional(proxyCapabilitiesDecoder),
};

const configStatisticsDecoderMapping = {
  active_tell_commands: JsonDecoder.number,
  active_priv_commands: JsonDecoder.number,
  active_org_commands: JsonDecoder.number,
  active_subcommands: JsonDecoder.number,
  active_aliases: JsonDecoder.number,
  active_events: JsonDecoder.number,
  active_help_commands: JsonDecoder.number,
};

const systemStatsDecoderMapping = {
  buddy_list_size: JsonDecoder.number,
  max_buddy_list_size: JsonDecoder.number,
  priv_channel_size: JsonDecoder.number,
  org_size: JsonDecoder.number,
  charinfo_cache_size: JsonDecoder.number,
  chatqueue_length: JsonDecoder.number,
};

const channelInfoDecoderMapping = {
  name: JsonDecoder.string,
  id: JsonDecoder.number,
};

const basicSystemInformationDecoder =
  JsonDecoder.objectStrict<BasicSystemInformation>(
    basicSystemInformationDecoderMapping,
    "BasicSystemInformation"
  );

const memoryInformationDecoder = JsonDecoder.objectStrict<MemoryInformation>(
  memoryInformationDecoderMapping,
  "MemoryInformation"
);

const miscSystemInformationDecoder =
  JsonDecoder.objectStrict<MiscSystemInformation>(
    miscSystemInformationDecoderMapping,
    "MiscSystemInformation"
  );

const configStatisticsDecoder = JsonDecoder.objectStrict<ConfigStatistics>(
  configStatisticsDecoderMapping,
  "ConfigStatistics"
);

const systemStatsDecoder = JsonDecoder.objectStrict<SystemStats>(
  systemStatsDecoderMapping,
  "SystemStats"
);

const channelInfoDecoder = JsonDecoder.objectStrict<ChannelInfo>(
  channelInfoDecoderMapping,
  "ChannelInfo"
);

const systemInformationDecoderMapping = {
  basic: basicSystemInformationDecoder,
  memory: memoryInformationDecoder,
  misc: miscSystemInformationDecoder,
  config: configStatisticsDecoder,
  stats: systemStatsDecoder,
  channels: JsonDecoder.array(channelInfoDecoder, "channels"),
};

export const systemInformationDecoder =
  JsonDecoder.objectStrict<SystemInformation>(
    systemInformationDecoderMapping,
    "SystemInformation"
  );
