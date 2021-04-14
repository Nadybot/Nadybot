import { JsonDecoder } from "ts.data.json";

export interface NewsItem {
  readonly id: number;
  readonly time: number;
  readonly name: string;
  news: string;
  sticky: boolean;
  deleted: boolean;
}

const newsItemDecoderMapping = {
  id: JsonDecoder.number,
  time: JsonDecoder.number,
  name: JsonDecoder.string,
  news: JsonDecoder.string,
  sticky: JsonDecoder.boolean,
  deleted: JsonDecoder.boolean,
};

const newsItemDecoder = JsonDecoder.object<NewsItem>(
  newsItemDecoderMapping,
  "NewsItem"
);

export const newsDecoder = JsonDecoder.array<NewsItem>(newsItemDecoder, "News");
