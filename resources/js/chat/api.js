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

        messages: (conversationId, before = null, limit = null) =>
            data(axios.get(url('messages', conversationId), { params: { ...(before ? { before } : {}), ...(limit ? { limit } : {}) } })),
        searchMessages: (conversationId, q, signal) => data(axios.get(url('messagesSearch', conversationId), { params: { q }, signal })),

        sendMessage: (conversationId, payload, config = {}) =>
            data(axios.post(url('messagesStore', conversationId), payload, config)),

        searchUsers: (q, signal) => data(axios.get(url('search'), { params: { q }, signal })),
        onlineUsers: () => data(axios.get(url('online'))),

        // Phase 3+
        markSeen: (conversationId) => data(axios.post(url('seen', conversationId))),
        markDelivered: (ids) => data(axios.post(url('delivered'), { ids })),
        typing: (conversationId, isTyping, action = 'typing') => data(axios.post(url('typing', conversationId), { typing: isTyping, action })),
        heartbeat: () => data(axios.post(url('heartbeat'))),
        sync: (params) => data(axios.get(url('sync'), { params })),

        // Phase 4+
        updateMessage: (id, message) => data(axios.patch(url('messageUpdate', id), { message })),
        deleteMessage: (id, scope) => data(axios.delete(url('messageDestroy', id), { data: { scope } })),
        forwardMessage: (id, conversationIds) => data(axios.post(url('messageForward', id), { conversation_ids: conversationIds })),
        react: (id, emoji) => data(axios.put(url('messageReaction', id), { emoji })),
        unreact: (id) => data(axios.delete(url('messageReaction', id))),
        pin: (id, duration) => data(axios.put(url('messagePin', id), { duration })),
        unpin: (id) => data(axios.delete(url('messagePin', id))),
        star: (id) => data(axios.put(url('messageStar', id))),
        unstar: (id) => data(axios.delete(url('messageStar', id))),
        starred: (before = null) => data(axios.get(url('starred'), { params: before ? { before } : {} })),
        openViewOnce: (id) => data(axios.post(url('viewOnce', id))),
        setDisappearing: (conversationId, seconds) => data(axios.put(url('disappearing', conversationId), { seconds })),
        vote: (id, options) => data(axios.put(url('messageVote', id), { options })),
        updateLocation: (id, coords) => data(axios.patch(url('messageLocation', id), coords)),
        stopLocation: (id) => data(axios.delete(url('messageLocation', id))),
        stickers: () => data(axios.get(url('stickers'))),
        createSticker: (form) => data(axios.post(url('stickersStore'), form)),
        deleteSticker: (id) => data(axios.delete(url('stickerDestroy', id))),
        saveSticker: (messageId) => data(axios.post(url('messageSaveSticker', messageId))),
        gifs: (q, pos = null) => data(axios.get(url('gifs'), { params: { ...(q ? { q } : {}), ...(pos ? { pos } : {}) } })),
        // POST keeps typed links out of server access logs.
        linkPreview: (link) => data(axios.post(url('linkPreview'), { url: link })),

        // Phase 5+
        block: (userId) => data(axios.post(url('block', userId))),
        unblock: (userId) => data(axios.delete(url('unblock', userId))),
    };
}
