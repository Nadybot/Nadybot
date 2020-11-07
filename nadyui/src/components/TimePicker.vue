<template>
  <div class="input-group">
    <select
      class="form-control col-4 custom-select custom-select-sm"
      v-model.number="hour"
      @change="update"
    >
      <option v-for="hour in 24" :key="hour" :value="hour - 1">
        {{ hour - 1 }}h
      </option>
    </select>
    <select
      class="form-control col-4 custom-select custom-select-sm"
      v-model.number="minute"
      @change="update"
    >
      <option v-for="minute in 60" :key="minute" :value="minute - 1">
        {{ minute - 1 }}m
      </option>
    </select>
    <select
      class="form-control col-4 custom-select custom-select-sm"
      v-model.number="second"
      @change="update"
    >
      <option v-for="second in 60" :key="second" :value="second - 1">
        {{ second - 1 }}s
      </option>
    </select>
    <div class="col-2 input-group-append" style="margin-left: -15px !important">
      <span class="btn btn-sm btn-secondary disabled"><fa icon="clock" /></span>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.input-group-append {
  padding-right: 0;
}
</style>

<script lang="ts">
import { defineComponent } from "vue";

export default defineComponent({
  name: "TimePicker",
  props: {
    modelValue: {
      type: Number,
      required: false,
      default: 0,
    },
  },
  methods: {
    update: function () {
      this.$emit(
        "update:modelValue",
        this.hour * 3600 + this.minute * 60 + this.second
      );
    },
  },
  data: function () {
    return {
      hour: 0,
      minute: 0,
      second: 0,
    };
  },
  created() {
    this.hour = Math.floor(this.modelValue / 3600);
    let left_seconds = this.modelValue - this.hour * 3600;
    this.minute = Math.floor(left_seconds / 60);
    this.second = left_seconds - this.minute * 60;
  },
});
</script>
