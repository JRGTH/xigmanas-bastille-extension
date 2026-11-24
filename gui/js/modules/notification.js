// modules/notification.js
// Browser notifications, shared by every view (editor and releases).

const ICON = "/ext/bastille/images/logo-xigmanas.png";

// Ask for permission once. Call it from a user gesture (a click): browsers may ignore the
// request otherwise.
export async function initNotifications() {
  if (!("Notification" in window)) {
    return;
  }
  if (Notification.permission === "default") {
    const permission = await Notification.requestPermission();
    console.log("[IDE] Notification Permission:", permission);
  }
}

// --- NOTIFICATION ---
export function showNotification(title, bodyText) {
  if (!("Notification" in window)) {
    return;
  }

  const options = { body: bodyText, icon: ICON };

  if (Notification.permission === "granted") {
    new Notification(title, options);
  } else if (Notification.permission !== "denied") {
    // Not decided yet: ask now and show it if the user allows it.
    Notification.requestPermission().then((permission) => {
      if (permission === "granted") {
        new Notification(title, options);
      }
    });
  } else {
    console.warn("[IDE] Blocked notifications");
  }
}
