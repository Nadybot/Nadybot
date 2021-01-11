const parser = new DOMParser();

export function parseXml(body: string): XMLDocument {
  return parser.parseFromString(body, "text/xml");
}

const itemRegex = /<item lowid="(\d+)" highid="(\d+)" ql="(\d+)">(.*?)<\/item>/gm;

export function replaceItemRefs(text: string): string {
  const matches = text.replace(
    itemRegex,
    `<a href="itemref://$1/$2/$3">$4</a>`
  );
  return matches;
}
