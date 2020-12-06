const parser = new DOMParser();

export function parseXml(body: string): XMLDocument {
  // A little hack so we can iterate the children of the root node
  body = `<msg>${body}</msg>`;
  return parser.parseFromString(body, "text/xml");
}

function formatNode(messageId: number, node: Element): string {
  // Figure the starting element based on node type
  let result = "";
  let closingTag: string | null = null;
  if (node.nodeName == "strong") {
    result += "<strong>";
    closingTag = "</strong>";
  } else if (node.nodeName == "color") {
    const value = node.getAttribute("value");
    if (value) {
      result += `<span style="color:${value}">`;
    } else {
      result += `<span>`;
    }
    closingTag = "</span>";
  } else if (node.nodeName == "h1") {
    result += "<h2>";
    closingTag = "</h2>";
  } else if (node.nodeName == "h2") {
    result += "<h3>";
    closingTag = "</h3>";
  } else if (node.nodeName == "br") {
    result += "<br />";
  } else if (node.nodeName == "tab") {
    result += "&#09;";
  } else if (node.nodeName == "ul") {
    result += "<ul>";
    closingTag = "</ul>";
  } else if (node.nodeName == "li") {
    result += `<li>`;
    closingTag = "</li>";
  } else if (node.nodeName == "item") {
    const lowId = node.getAttribute("lowid");
    const highId = node.getAttribute("highid");
    const ql = node.getAttribute("ql");
    if (lowId && highId && ql) {
      result += `<a href="https://aoitems.com/item/${lowId}/${ql}/">`;
    } else {
      result += "<a>";
    }
    closingTag = "</a>";
  } else if (node.nodeName == "nano") {
    const id = node.getAttribute("id");
    if (id) {
      result += `<a href="https://aoitems.com/item/${id}/">`;
    } else {
      result += "<a>";
    }
    closingTag = "</a>";
  } else if (node.nodeName == "command") {
    const targetCommand = node.getAttribute("cmd");
    if (targetCommand) {
      result += `<span class="triggers-action" onclick="executeCommand('${targetCommand}')">`;
    } else {
      result += "<span>";
    }
    closingTag = "</span>";
  } else if (node.nodeName == "a") {
    const target = node.getAttribute("href");
    if (target) {
      result += `<a href="${target}">`;
    } else {
      result += "<a>";
    }
    closingTag = "</a>";
  } else if (node.nodeName == "img") {
    const rdb = node.getAttribute("rdb");
    if (rdb) {
      result += `<img src="https://static.aoitems.com/icon/${rdb}">`;
      closingTag = "</img>";
    }
  } else if (node.nodeName == "i") {
    result += "<i>";
    closingTag = "</i>";
  } else if (node.nodeName == "popup") {
    const popupId = node.getAttribute("id");
    if (popupId) {
      result += `<span class="triggers-action" onclick="togglePopup(${messageId}, ${popupId})">`;
    } else {
      result += "<span>";
    }
    closingTag = `</span>`;
  }
  node.childNodes.forEach(function (child) {
    if (child instanceof Text) {
      result += child.textContent;
    } else if (child instanceof Element) {
      result += formatNode(messageId, child);
    }
  });
  if (closingTag) {
    result += closingTag;
  }
  return result;
}

export function formatXmlDocument(id: number, document: XMLDocument): string {
  const rootNode = document.firstChild;
  if (!rootNode) {
    return "";
  }
  return formatNode(id, rootNode as Element);
}
