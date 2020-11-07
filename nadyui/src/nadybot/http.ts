import axios from "axios";
import { OnlinePlayers, onlinePlayersDecoder } from "./types/player";
import { SystemInformation, systemInformationDecoder } from "./types/stats";
import {
  ConfigModule,
  ModuleSetting,
  moduleSettingArrayDecoder,
  configModuleArrayDecoder,
  ModuleEventConfig,
  moduleEventConfigArrayDecoder,
  ModuleCommand,
  moduleCommandArrayDecoder,
  ModuleAccessLevel,
  moduleAccessLevelArrayDecoder,
} from "./types/settings";

export async function getOnlineMembers(): Promise<OnlinePlayers> {
  const response = await axios.get("/api/online");
  return await onlinePlayersDecoder.decodePromise(response.data);
}

export async function getSystemInformation(): Promise<SystemInformation> {
  const response = await axios.get("/api/sysinfo");
  return await systemInformationDecoder.decodePromise(response.data);
}

export async function getModules(): Promise<Array<ConfigModule>> {
  const response = await axios.get("/api/module");
  return await configModuleArrayDecoder.decodePromise(response.data);
}

export async function getModuleSettings(
  moduleName: string
): Promise<Array<ModuleSetting>> {
  const response = await axios.get(`/api/module/${moduleName}/settings`);
  return await moduleSettingArrayDecoder.decodePromise(response.data);
}

export async function getModuleEvents(
  moduleName: string
): Promise<Array<ModuleEventConfig>> {
  const response = await axios.get(`/api/module/${moduleName}/events`);
  return await moduleEventConfigArrayDecoder.decodePromise(response.data);
}

export async function getModuleCommands(
  moduleName: string
): Promise<Array<ModuleCommand>> {
  const response = await axios.get(`/api/module/${moduleName}/commands`);
  return await moduleCommandArrayDecoder.decodePromise(response.data);
}

export async function getAccessLevels(): Promise<Array<ModuleAccessLevel>> {
  const response = await axios.get("/api/access_levels");
  return await moduleAccessLevelArrayDecoder.decodePromise(response.data);
}

export async function toggleModule(
  name: string,
  enabled: boolean
): Promise<void> {
  const op = enabled ? "enable" : "disable";
  await axios.put(`/api/module/${name}`, { op: op });
}

export async function toggleEvent(
  module: string,
  name: string,
  handler: string,
  enabled: boolean
): Promise<void> {
  const op = enabled ? "enable" : "disable";
  await axios.put(`/api/module/${module}/events/${name}/${handler}`, {
    op: op,
  });
}

export async function toggleCommand(
  module: string,
  name: string,
  channel: string,
  access_level: string,
  enabled: boolean
): Promise<void> {
  await axios.put(`/api/module/${module}/commands/${name}/${channel}`, {
    access_level: access_level,
    enabled: enabled,
  });
}
