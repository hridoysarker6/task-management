import { router } from '@inertiajs/vue3';

// Saving one field of a task from a list, card or panel. Each helper returns a promise that resolves when the server
// accepted the change and rejects with the server's own message (a refused move, a person outside your scope...).
const firstMessage = (errors) => Object.values(errors ?? {})[0] ?? 'That could not be saved.';

const send = (method, url, data) =>
    new Promise((resolve, reject) => {
        router[method](url, data, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => resolve(),
            onError: (errors) => reject(new Error(firstMessage(errors))),
        });
    });

export const patchTask = (key, data) => send('patch', route('tasks.update', key), data);

export const assignTask = (key, assigneeId) => send('post', route('tasks.assign', key), { assignee_id: assigneeId });

export const moveTask = (key, statusId, extra = {}) => send('post', route('tasks.status', key), { status_id: statusId, ...extra });
