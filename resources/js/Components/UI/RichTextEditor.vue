<script setup>
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { onBeforeUnmount, watch } from 'vue';

// A small rich-text box (bold, italic, lists, quote, code, link). The server cleans the HTML again before saving.
const model = defineModel({ type: String, default: '' });
const props = defineProps({
    placeholder: { type: String, default: '' },
    editable: { type: Boolean, default: true },
    minHeight: { type: String, default: '8rem' },
});

const editor = useEditor({
    content: model.value || '',
    editable: props.editable,
    extensions: [StarterKit.configure({ heading: { levels: [2, 3] }, link: { openOnClick: false, autolink: true, HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' } } })],
    editorProps: { attributes: { class: 'prose-sm max-w-none px-3 py-2 focus:outline-none', 'aria-label': 'Rich text editor' } },
    onUpdate: ({ editor }) => {
        model.value = editor.isEmpty ? '' : editor.getHTML();
    },
});

// Keep the editor in step when the parent replaces the value (e.g. after saving or cancelling).
watch(model, (value) => {
    if (editor.value && value !== (editor.value.isEmpty ? '' : editor.value.getHTML())) {
        editor.value.commands.setContent(value || '', false);
    }
});
watch(() => props.editable, (v) => editor.value?.setEditable(v));
onBeforeUnmount(() => editor.value?.destroy());

const btn = (active) => ['rounded px-2 py-1 text-xs font-medium', active ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'];

const setLink = () => {
    const previous = editor.value.getAttributes('link').href;
    const url = window.prompt('Link address (https://…)', previous ?? 'https://');
    if (url === null) return;
    if (url === '') return editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
    editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
};
</script>

<template>
    <div class="rounded-lg border border-gray-300 bg-white focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
        <div v-if="editor && editable" class="flex flex-wrap gap-0.5 border-b border-gray-200 px-1.5 py-1" role="toolbar" aria-label="Formatting">
            <button type="button" :class="btn(editor.isActive('bold'))" aria-label="Bold" @click="editor.chain().focus().toggleBold().run()"><b>B</b></button>
            <button type="button" :class="btn(editor.isActive('italic'))" aria-label="Italic" @click="editor.chain().focus().toggleItalic().run()"><i>I</i></button>
            <button type="button" :class="btn(editor.isActive('strike'))" aria-label="Strikethrough" @click="editor.chain().focus().toggleStrike().run()"><s>S</s></button>
            <button type="button" :class="btn(editor.isActive('bulletList'))" aria-label="Bulleted list" @click="editor.chain().focus().toggleBulletList().run()">&bull; List</button>
            <button type="button" :class="btn(editor.isActive('orderedList'))" aria-label="Numbered list" @click="editor.chain().focus().toggleOrderedList().run()">1. List</button>
            <button type="button" :class="btn(editor.isActive('blockquote'))" aria-label="Quote" @click="editor.chain().focus().toggleBlockquote().run()">&ldquo; Quote</button>
            <button type="button" :class="btn(editor.isActive('code'))" aria-label="Code" @click="editor.chain().focus().toggleCode().run()">&lt;/&gt;</button>
            <button type="button" :class="btn(editor.isActive('link'))" aria-label="Link" @click="setLink">Link</button>
        </div>
        <div :style="{ minHeight }" class="task-rich-text">
            <EditorContent :editor="editor" />
            <p v-if="editor && editor.isEmpty && placeholder" class="pointer-events-none -mt-8 px-3 pb-2 text-sm text-gray-400">{{ placeholder }}</p>
        </div>
    </div>
</template>

<style>
.task-rich-text .tiptap p { margin: 0.25rem 0; }
.task-rich-text .tiptap ul { list-style: disc; padding-left: 1.25rem; }
.task-rich-text .tiptap ol { list-style: decimal; padding-left: 1.25rem; }
.task-rich-text .tiptap blockquote { border-left: 3px solid #d1d5db; padding-left: 0.75rem; color: #4b5563; }
.task-rich-text .tiptap code { background: #f3f4f6; padding: 0 0.25rem; border-radius: 0.25rem; font-size: 0.85em; }
.task-rich-text .tiptap pre { background: #1f2937; color: #f9fafb; padding: 0.5rem 0.75rem; border-radius: 0.5rem; overflow-x: auto; }
.task-rich-text .tiptap a { color: #7c3aed; text-decoration: underline; }
.task-rich-text .tiptap h2 { font-size: 1.1rem; font-weight: 600; }
.task-rich-text .tiptap h3 { font-size: 1rem; font-weight: 600; }
.rich-html p { margin: 0.25rem 0; }
.rich-html ul { list-style: disc; padding-left: 1.25rem; }
.rich-html ol { list-style: decimal; padding-left: 1.25rem; }
.rich-html blockquote { border-left: 3px solid #d1d5db; padding-left: 0.75rem; color: #4b5563; }
.rich-html code { background: #f3f4f6; padding: 0 0.25rem; border-radius: 0.25rem; font-size: 0.85em; }
.rich-html pre { background: #1f2937; color: #f9fafb; padding: 0.5rem 0.75rem; border-radius: 0.5rem; overflow-x: auto; }
.rich-html a { color: #7c3aed; text-decoration: underline; }
.rich-html h2 { font-size: 1.1rem; font-weight: 600; }
.rich-html h3 { font-size: 1rem; font-weight: 600; }
</style>
