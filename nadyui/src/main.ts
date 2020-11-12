import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";
import store from "./store";

import FontAwesomeIcon from "@/components/FontAwesomeIcon.vue";
import ProfessionIcon from "@/components/ProfessionIcon.vue";
import Sidebar from "@/components/Sidebar.vue";
import TimePicker from "@/components/TimePicker.vue";
import SmartTable from "vuejs-smart-table";

createApp(App)
  .use(store)
  .use(router)
  .use(SmartTable)
  .component("sidebar", Sidebar)
  .component("fa", FontAwesomeIcon)
  .component("profession-icon", ProfessionIcon)
  .component("time-picker", TimePicker)
  .mount("#app");
