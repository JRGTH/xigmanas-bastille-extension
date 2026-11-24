// release_watch_main.js
// Entry point for pages that only need to tell the user when a release job ends
// (bastille_manager_gui.php). The Base Releases page has its own, release_main.js.

import { watchPendingRelease } from "./modules/release-notify.js";

document.addEventListener("DOMContentLoaded", watchPendingRelease);
