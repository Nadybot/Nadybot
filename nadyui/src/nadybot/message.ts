const parser = new DOMParser();

// Somewhat hacky way to get all images with proper URLs after webpack
const profIcons: Record<string, string> = {
  Adventurer: require("@/assets/Adventurer.svg"),
  Agent: require("@/assets/Agent.svg"),
  Bureaucrat: require("@/assets/Bureaucrat.svg"),
  Doctor: require("@/assets/Doctor.svg"),
  Enforcer: require("@/assets/Enforcer.svg"),
  Engineer: require("@/assets/Engineer.svg"),
  Fixer: require("@/assets/Fixer.svg"),
  Keeper: require("@/assets/Keeper.svg"),
  "Martial Artist": require("@/assets/Martial Artist.svg"),
  "Meta-Physicist": require("@/assets/Meta-Physicist.svg"),
  "Nano-Technician": require("@/assets/Nano-Technician.svg"),
  Shade: require("@/assets/Shade.svg"),
  Soldier: require("@/assets/Soldier.svg"),
  Trader: require("@/assets/Trader.svg"),
};

export function parseXml(body: string): XMLDocument {
  // A little hack so we can iterate the children of the root node
  body = `<msg>${body}</msg>`;
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

function encodeSpecialCharacters(input: string): string {
  return input.replaceAll('"', "&quot;");
}

function formatNode(messageId: number, node: Element): string {
  // Figure the starting element based on node type
  let result = "";
  let closingTag: string | null = null;
  if (node.nodeName == "strong") {
    result += `<strong class="make-lighter-strong">`;
    closingTag = "</strong>";
  } else if (node.nodeName == "color") {
    const value = node.getAttribute("value");
    if (value && value != "#000000") {
      result += `<span style="color:${value}">`;
    } else if (value) {
      result += `<span style="opacity:0">`;
    } else {
      result += `<span>`;
    }
    closingTag = "</span>";
  } else if (node.nodeName == "h1") {
    result += "<h4>";
    closingTag = "</h4>";
  } else if (node.nodeName == "h2") {
    result += `<h5 class="heading-orange">`;
    closingTag = "</h5>";
  } else if (node.nodeName == "br") {
    result += "<br />";
  } else if (node.nodeName == "tab") {
    result += "&emsp;";
  } else if (node.nodeName == "ul") {
    result += "<ul>";
    closingTag = "</ul>";
  } else if (node.nodeName == "li") {
    result += `<li>`;
    closingTag = "</li>";
  } else if (node.nodeName == "u") {
    result += "<u>";
    closingTag = "</u>";
  } else if (node.nodeName == "item") {
    const lowId = node.getAttribute("lowid");
    const highId = node.getAttribute("highid");
    const ql = node.getAttribute("ql");
    if (lowId && highId && ql) {
      result += `<a href="https://aoitems.com/item/${lowId}/${ql}/" target="_blank">`;
    } else {
      result += "<a>";
    }
    closingTag = "</a>";
  } else if (node.nodeName == "nano") {
    const id = node.getAttribute("id");
    if (id) {
      result += `<a href="https://aoitems.com/item/${id}/" target="_blank">`;
    } else {
      result += "<a>";
    }
    closingTag = "</a>";
  } else if (node.nodeName == "command") {
    const targetCommand = node.getAttribute("cmd");
    if (targetCommand) {
      const action = encodeSpecialCharacters(targetCommand);
      result += `<span class="triggers-action" onclick="executeCommand('${action}')">`;
    } else {
      result += "<span>";
    }
    closingTag = "</span>";
  } else if (node.nodeName == "a") {
    const target = node.getAttribute("href");
    if (target) {
      result += `<a href="${target}" target="_blank">`;
    } else {
      result += "<a>";
    }
    closingTag = "</a>";
  } else if (node.nodeName == "img") {
    const rdb = node.getAttribute("rdb");
    const prof = node.getAttribute("prof");
    if (rdb) {
      result += `<img src="https://static.aoitems.com/icon/${rdb}">`;
      closingTag = "</img>";
    } else if (prof) {
      result += `<img src="${profIcons[prof]}" class="prof-icon">`;
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

export function formatXmlDocumentPopup(document: XMLDocument): string {
  const rootNode = document.firstChild;
  if (!rootNode) {
    return "";
  }
  if (rootNode.firstChild) {
    rootNode.removeChild(rootNode.firstChild);
  }
  // Assume there are no popups in a popup
  return formatNode(0, rootNode as Element);
}

export function formatModalTitle(document: XMLDocument): string {
  const firstChild = document.firstChild;
  if (firstChild && firstChild.firstChild) {
    // No popups in popup title, so 0 is fine
    // It will be a h3 so strip those tags manually
    const formatted = formatNode(0, firstChild.firstChild as Element);
    return formatted.substr(4, formatted.length - 5);
  }
  return "";
}
