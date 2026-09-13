import axios from '../bootstrap';

/**
 * Thin wrapper around the chat HTTP endpoints.
 * Route templates come from the server with an `__ID__` placeholder.
 */
export function createApi(routes) {
    const url = (name, id) => {
        const template = routes[name];
        if (!template) throw new Error(`Route [${name}] is not available.`);
        return id === undefined ? template : template.replace('__ID__', encodeURIComponent(id));
    };

    const data = (promise) => promise.then((response) => response.data);

    return {
        has: (name) => Boolean(routes[name]),
        url,

        conversations: () => data(axios.get(url('conversations'))),
        conversation: (id) => data(axios.get(url('conversationShow', id))),
        startConversation: (userId) => data(axios.post(url('conversationsStore'), { user_id: userId })),

        messages: (conversationId, before = null) =>
            data(axios.get(url('messages', conversationId), { params: before ? { before } : {} })),

        sendMessage: (conversationId, payload, config = {}) =>
            data(axios.post(url('messagesStore', conversationId), payload, config)),

        searchUsers: (q, signal) => data(axios.get(url('search'), { params: { q }, signal })),
        onlineUsers: () => data(axios.get(url('online'))),

        // Phase 3+
        markSeen: (conversationId) => data(axios.post(url('seen', conversationId))),
        markDelivered: (ids) => data(axios.post(url('delivered'), { ids })),
        typing: (conversationId, isTyping) => data(axios.post(url('typing', conversationId), { typing: isTyping })),
        heartbeat: () => data(axios.post(url('heartbeat'))),
        sync: (params) => data(axios.get(url('sync'), { params })),

        // Phase 4+
        updateMessage: (id, message) => data(axios.patch(url('messageUpdate', id), { message })),
        deleteMessage: (id, scope) => data(axios.delete(url('messageDestroy', id), { data: { scope } })),

        // Phase 5+
        block: (userId) => data(axios.post(url('block', userId))),
        unblock: (userId) => data(axios.delete(url('unblock', userId))),
    };
}
