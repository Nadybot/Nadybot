<template>
  <h2 class="mb-3">News</h2>

  <div class="card mb-2">
    <div class="card-body">
      <h6 class="card-subtitle mb-2 text-muted">Create a new entry...</h6>
      <p class="card-text mb-0">
        <textarea
          :readonly="editing != -1"
          class="borderless"
          id="new-news"
          placeholder="Type your text here"
          v-model="new_text"
          :rows="(new_text.match(/\n/g) || []).length + 1"
          @click="editing = -1"
          @blur="create()"
        ></textarea>
      </p>
    </div>
  </div>

  <div
    v-for="item in news"
    :key="item"
    class="card mb-2"
    :class="{ ly: item.sticky }"
  >
    <div class="card-body">
      <h6 class="card-subtitle mb-2 text-muted">
        {{ item.name }}, {{ new Date(item.time * 1000).toISOString() }}
      </h6>
      <p class="card-text mb-0">
        <textarea
          :readonly="editing != item.id"
          class="borderless"
          :id="'borderless-' + item.id"
          v-model="item.news"
          :rows="(item.news.match(/\n/g) || []).length + 1"
          @blur="edit()"
        ></textarea>
      </p>
      <div class="icons">
        <img
          src="../assets/font-awesome-icons/edit.svg"
          @click="setEditing(item.id)"
        />
        <img
          src="../assets/font-awesome-icons/trash.svg"
          @click="deleteItem(item.id)"
        />
        <img
          v-if="item.sticky"
          src="../assets/font-awesome-icons/thumbtack.svg"
          class="sticky"
          @click="notSticky(item.id)"
        />
        <img
          v-else
          src="../assets/font-awesome-icons/thumbtack.svg"
          @click="sticky(item.id)"
        />
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.card-body {
  white-space: pre-wrap;
}

img {
  width: 20px;
  height: 20px;
  filter: invert(60%);
  margin-left: 7px;

  &:hover {
    filter: invert(30%);
    cursor: pointer;
  }

  &.sticky {
    filter: invert(30%);
  }
}

.icons {
  position: absolute;
  top: 10px;
  right: 10px;
}

.ly {
  background: lightyellow;

  .borderless {
    background: lightyellow;
  }
}

.borderless {
  border: none;
  border-color: transparent;
  outline: none;
  border-style: none;
  width: 100%;
  height: 100%;
  resize: none;

  &:not(:read-only) {
    outline: 1px solid lightgrey;
  }
}
</style>

<script lang="ts">
import { defineComponent } from "vue";

import {
  deleteNews,
  editNews,
  getNews,
  setNotSticky,
  setSticky,
  createNews,
} from "@/nadybot/http";
import { NewsItem } from "@/nadybot/types/news";

interface InfoData {
  news: Array<NewsItem>;
  editing: number | null;
  new_text: string;
}

export default defineComponent({
  name: "News",

  data(): InfoData {
    return {
      news: [],
      editing: null,
      new_text: "",
    };
  },

  methods: {
    reorder(): void {
      this.news = this.news.filter((n) => !n.deleted);
      this.news.sort(function (a, b) {
        if (a.sticky && !b.sticky) return -1;
        else if (!a.sticky && b.sticky) return 1;
        else if (a.time > b.time) return 1;
        else return -1;
      });
    },

    setEditing(id: number): void {
      this.editing = id;
      const elem = document.getElementById("borderless-" + id);
      if (elem) elem.focus();
    },

    async sticky(id: number): Promise<void> {
      await setSticky(id);
      const item = this.news.find((e) => e.id == id);
      if (item) item.sticky = true;
      this.reorder();
    },

    async notSticky(id: number): Promise<void> {
      await setNotSticky(id);
      const item = this.news.find((e) => e.id == id);
      if (item) item.sticky = false;
      this.reorder();
    },

    async deleteItem(id: number): Promise<void> {
      await deleteNews(id);
      const item = this.news.find((e) => e.id == id);
      if (item) item.deleted = true;
      this.reorder();
    },

    async create(): Promise<void> {
      if (this.editing == -1 && this.new_text.length > 0) {
        await createNews(this.new_text);
        this.new_text = "";
        this.news = await getNews();
        this.reorder();
      }
      this.editing = null;
    },

    async edit(): Promise<void> {
      const elem = document.getElementById(
        "borderless-" + this.editing
      ) as HTMLInputElement;
      if (elem && this.editing) {
        const news = elem.value;
        await editNews(this.editing, news);
        const item = this.news.find((e) => e.id == this.editing);
        if (item) item.news = news;
        this.editing = null;
      }
    },
  },

  async created(): Promise<void> {
    this.news = await getNews();
    this.reorder();
  },
});
</script>
