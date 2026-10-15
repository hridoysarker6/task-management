<script setup>
import Modal from '@/Components/Modal.vue';
import { SHORTCUT_GROUPS, helpOpen } from '@/Composables/useShortcuts';
import { XMarkIcon } from '@heroicons/vue/24/outline';
</script>

<template>
    <Modal :show="helpOpen" max-width="xl" @close="helpOpen = false">
        <div class="p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900">Keyboard shortcuts</h2>
                <button type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="Close" @click="helpOpen = false"><XMarkIcon class="h-5 w-5" /></button>
            </div>
            <p class="mt-1 text-sm text-gray-500">Shortcuts work when you are not typing in a field.</p>

            <div class="mt-5 grid gap-6 sm:grid-cols-2">
                <section v-for="g in SHORTCUT_GROUPS" :key="g.title">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ g.title }}</h3>
                    <ul class="space-y-1.5">
                        <li v-for="i in g.items" :key="i.text" class="flex items-center justify-between gap-3 text-sm">
                            <span class="text-gray-700">{{ i.text }}</span>
                            <span class="flex shrink-0 items-center gap-1">
                                <template v-for="(k, n) in i.keys" :key="k">
                                    <span v-if="n" class="text-xs text-gray-400">then</span>
                                    <kbd class="min-w-[1.5rem] rounded-md border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-center text-xs font-semibold text-gray-600 shadow-sm">{{ k }}</kbd>
                                </template>
                            </span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </Modal>
</template>
