<script setup>
defineProps({
    columns: { type: Array, required: true }, // [{ key, label, class? }]
    rows: { type: Array, required: true },
    rowKey: { type: String, default: 'id' },
    emptyText: { type: String, default: 'No records found.' },
});
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th
                            v-for="c in columns"
                            :key="c.key"
                            scope="col"
                            :class="['px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500', c.class]"
                        >
                            {{ c.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="row in rows" :key="row[rowKey]" class="hover:bg-gray-50">
                        <td v-for="c in columns" :key="c.key" :class="['px-4 py-3 text-gray-700', c.class]">
                            <slot :name="`cell-${c.key}`" :row="row" :value="row[c.key]">{{ row[c.key] }}</slot>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td :colspan="columns.length" class="px-4 py-10 text-center text-gray-500">{{ emptyText }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="$slots.footer" class="border-t border-gray-200 px-4 py-3"><slot name="footer" /></div>
    </div>
</template>
