<template>
  <strong v-if="content.nodeName == 'strong'">
    <template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </strong>

  <span
    v-else-if="content.nodeName == 'color'"
    :style="'color:' + content.getAttribute('fg')"
    :class="{ invisible: content.getAttribute('fg') == '#000000' }"
    ><template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </span>

  <!-- In the popup header, we do not send the outer h4 -->
  <template v-else-if="content.nodeName == 'h1'">
    <h4 v-if="!formatForTitle">
      <template v-for="child in content.childNodes" :key="child">
        <template v-if="isTextNode(child)">
          {{ child.textContent }}
        </template>
        <ao-message
          v-else
          :content="child"
          @open-popup="$emit('open-popup', $event)"
          @run-command="$emit('run-command', $event)"
        ></ao-message>
      </template>
    </h4>
    <template v-else v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </template>

  <h5 v-else-if="content.nodeName == 'h2'">
    <template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </h5>

  <br v-else-if="content.nodeName == 'br'" />

  <template v-else-if="content.nodeName == 'indent'">&emsp;</template>

  <ul v-else-if="content.nodeName == 'ul'">
    <template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </ul>

  <li v-else-if="content.nodeName == 'li'">
    <template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </li>

  <u v-else-if="content.nodeName == 'u'"
    ><template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </u>

  <a
    v-else-if="content.nodeName == 'ao:item'"
    :href="
      'https://aoitems.com/item/' +
      content.getAttribute('lowid') +
      '/' +
      content.getAttribute('ql')
    "
    target="_blank"
    ><template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </a>

  <a
    v-else-if="content.nodeName == 'ao:nano'"
    :href="'https://aoitems.com/item/' + content.getAttribute('id')"
    target="_blank"
    ><template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </a>

  <span
    v-else-if="content.nodeName == 'ao:command'"
    class="triggers-action"
    @click="$emit('run-command', content.getAttribute('cmd'))"
    ><template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </span>

  <span
    v-else-if="content.nodeName == 'popup'"
    class="triggers-action"
    @click="$emit('open-popup', content.getAttribute('ref'))"
    ><template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </span>

  <a
    v-else-if="content.nodeName == 'a'"
    :href="content.getAttribute('href')"
    target="_blank"
    ><template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </a>

  <a v-else-if="content.nodeName == 'ao:user'"
    ><template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </a>

  <template v-else-if="content.nodeName == 'ao:img'">
    <img
      v-if="content.getAttribute('rdb')"
      :src="'https://static.aoitems.com/icon/' + content.getAttribute('rdb')"
    />
    <profession-icon
      v-else-if="content.getAttribute('prof')"
      :profession="content.getAttribute('prof')"
    ></profession-icon>
  </template>

  <i v-else-if="content.nodeName == 'i'"
    ><template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </i>

  <template v-else>
    <template v-for="child in content.childNodes" :key="child">
      <template v-if="isTextNode(child)">
        {{ child.textContent }}
      </template>
      <ao-message
        v-else
        :content="child"
        @open-popup="$emit('open-popup', $event)"
        @run-command="$emit('run-command', $event)"
      ></ao-message>
    </template>
  </template>
</template>

<style lang="scss" scoped>
strong {
  color: #bff;
}

.invisible {
  opacity: 0;
}

.triggers-action {
  color: #5798f9;
  text-decoration: underline;

  &:hover {
    color: #397fe6;
    cursor: pointer;
  }
}

h5 {
  color: orange;
  margin-bottom: 0;

  &:not(:first-child) {
    margin-top: 16px;
  }
}
</style>

<script lang="ts">
import { defineComponent } from "vue";

export default defineComponent({
  name: "AOMessage",

  props: {
    content: {
      type: Node,
      required: true,
    },
    formatForTitle: {
      type: Boolean,
      required: false,
      default: false,
    },
  },

  emits: ["run-command", "open-popup"],

  methods: {
    isTextNode: function (element: Node): boolean {
      return element instanceof Text;
    },
  },
});
</script>
