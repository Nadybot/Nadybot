import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";
import store from "./store";

import { library } from "@fortawesome/fontawesome-svg-core";
import { fas } from "@fortawesome/free-solid-svg-icons";
import FontAwesomeIcon from "@/components/FontAwesomeIcon.vue";
import Sidebar from "@/components/Sidebar.vue";
import UserCard from "@/components/UserCard.vue";

library.add(fas);

createApp(App)
  .use(store)
  .use(router)
  .component("sidebar", Sidebar)
  .component("user-card", UserCard)
  .component("fa", FontAwesomeIcon)
  .mount("#app");
