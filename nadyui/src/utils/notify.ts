export async function notify(title: string, content: string): Promise<void> {
  if (window.Notification) {
    // check if permission is already granted
    if (Notification.permission === "granted") {
      // show notification here
      new Notification(title, {
        body: content,
        icon: require("../assets/logo.png"),
      });
    } else if (Notification.permission != "denied") {
      // request permission from user
      const p = await requestPermission();

      if (p === "granted") {
        // show notification here
        new Notification(title, {
          body: require("../assets/logo.png"),
        });
      }
    }
  }
}

export async function requestPermission(): Promise<NotificationPermission> {
  if (window.Notification) {
    return await Notification.requestPermission();
  } else {
    return "denied";
  }
}
