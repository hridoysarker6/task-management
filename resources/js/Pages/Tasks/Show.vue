<script setup>
import TaskDetail from '@/Components/Tasks/TaskDetail.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

// The task page. The whole body lives in TaskDetail so the side panel can show exactly the same thing.
// Inertia hands the page its props; they are passed straight on.
defineOptions({ inheritAttrs: false });
</script>

<template>
    <AppLayout>
        <TaskDetail v-bind="$attrs" />
    </AppLayout>
</template>
