import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";
import store from "./store";

import { library } from "@fortawesome/fontawesome-svg-core";
import { fas } from "@fortawesome/free-solid-svg-icons";
import FontAwesomeIcon from "@/components/FontAwesomeIcon.vue";
import ProfessionIcon from "@/components/ProfessionIcon.vue";
import Sidebar from "@/components/Sidebar.vue";
import Toggle from "@/components/Toggle.vue";
import TriToggle from "@/components/TriToggle.vue";
import SmartTable from "vuejs-smart-table";

library.add(fas);

createApp(App)
  .use(store)
  .use(router)
  .use(SmartTable)
  .component("sidebar", Sidebar)
  .component("fa", FontAwesomeIcon)
  .component("profession-icon", ProfessionIcon)
  .component("toggle", Toggle)
  .component("tri-toggle", TriToggle)
  .mount("#app");
