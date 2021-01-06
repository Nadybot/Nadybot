import { createRouter, createWebHashHistory, RouteRecordRaw } from "vue-router";
import Home from "../views/Home.vue";

const routes: Array<RouteRecordRaw> = [
  {
    path: "/",
    name: "Home",
    component: Home,
  },
  {
    path: "/users",
    name: "Users",
    // Lazy-loaded component
    component: () =>
      import(/* webpackChunkName: "users" */ "../views/Users.vue"),
  },
  {
    path: "/settings",
    name: "Settings",
    component: () =>
      import(/* webpackChunkName: "settings" */ "../views/Settings.vue"),
  },
  {
    path: "/console",
    name: "Console",
    component: () =>
      import(/* webpackChunkName: "console" */ "../views/Console.vue"),
  },
  {
    path: "/chat",
    name: "Chat",
    component: () =>
      import(/* webpackChunkName: "console" */ "../views/Chat.vue"),
  },
];

const router = createRouter({
  history: createWebHashHistory(),
  routes,
});

export default router;
