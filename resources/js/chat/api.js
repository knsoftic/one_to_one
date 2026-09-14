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
        // Groups (Phase 4)
        messageReceipts: (id) => data(axios.get(url('messageReceipts', id))),
        statuses: () => data(axios.get(url('statuses'))),
        createStatus: (payload) => data(axios.post(url('statusesStore'), payload)),
        deleteStatus: (id) => data(axios.delete(url('statusDestroy', id))),
        viewStatus: (id) => data(axios.post(url('statusView', id))),
        statusViewers: (id) => data(axios.get(url('statusViewers', id))),
        replyStatus: (id, message) => data(axios.post(url('statusReply', id), { message })),
        reactStatus: (id, emoji) => data(axios.post(url('statusReact', id), { emoji })),
        statusPrivacy: () => data(axios.get(url('statusPrivacy'))),
        updateStatusPrivacy: (payload) => data(axios.put(url('statusPrivacyUpdate'), payload)),
        muteStatus: (userId) => data(axios.post(url('statusMute', userId))),
        unmuteStatus: (userId) => data(axios.delete(url('statusUnmute', userId))),
        channels: (q = '') => data(axios.get(url('channels'), { params: q ? { q } : {} })),
        createChannel: (form) => data(axios.post(url('channelsStore'), form)),
        channel: (id) => data(axios.get(url('channelShow', id))),
        updateChannel: (id, form) => data(axios.post(url('channelUpdate', id), form)),
        deleteChannel: (id) => data(axios.delete(url('channelDestroy', id))),
        followChannel: (id) => data(axios.post(url('channelFollow', id))),
        unfollowChannel: (id) => data(axios.delete(url('channelUnfollow', id))),
        communities: () => data(axios.get(url('communities'))),
        createCommunity: (form) => data(axios.post(url('communitiesStore'), form)),
        updateCommunity: (id, form) => data(axios.post(url('communityUpdate', id), form)),
        deleteCommunity: (id) => data(axios.delete(url('communityDestroy', id))),
        leaveCommunity: (id) => data(axios.post(url('communityLeave', id))),
        createCommunityGroup: (id, payload) => data(axios.post(url('communityGroupsStore', id), payload)),
        linkCommunityGroup: (id, groupId) => data(axios.post(url('communityGroupLink', id).replace('__GROUP__', encodeURIComponent(groupId)))),
        unlinkCommunityGroup: (id, groupId) => data(axios.delete(url('communityGroupUnlink', id).replace('__GROUP__', encodeURIComponent(groupId)))),
        joinCommunityGroup: (id, groupId) => data(axios.post(url('communityGroupJoin', id).replace('__GROUP__', encodeURIComponent(groupId)))),
        communityInvite: (id) => data(axios.get(url('communityInvite', id))),
        resetCommunityInvite: (id) => data(axios.post(url('communityInviteReset', id))),
        joinCommunity: (token) => data(axios.post(url('communityJoin', token))),
        createBroadcast: (payload) => data(axios.post(url('broadcastsStore'), payload)),
        updateBroadcast: (id, payload) => data(axios.patch(url('broadcastUpdate', id), payload)),
        deleteBroadcast: (id) => data(axios.delete(url('broadcastDestroy', id))),
        createGroup: (form) => data(axios.post(url('groupsStore'), form)),
        updateGroup: (id, form) => data(axios.post(url('groupUpdate', id), form)),
        groupSettings: (id, settings) => data(axios.patch(url('groupSettings', id), settings)),
        addGroupMembers: (id, userIds) => data(axios.post(url('groupMembersStore', id), { user_ids: userIds })),
        setGroupRole: (id, userId, role) => data(axios.patch(url('groupMemberUpdate', id).replace('__USER__', encodeURIComponent(userId)), { role })),
        removeGroupMember: (id, userId) => data(axios.delete(url('groupMemberDestroy', id).replace('__USER__', encodeURIComponent(userId)))),
        leaveGroup: (id) => data(axios.post(url('groupLeave', id))),
        deleteGroup: (id) => data(axios.delete(url('groupDestroy', id))),
        groupInvite: (id) => data(axios.get(url('groupInvite', id))),
        resetGroupInvite: (id) => data(axios.post(url('groupInviteReset', id))),
        joinGroup: (token) => data(axios.post(url('groupJoin', token))),
        callLinks: () => data(axios.get(url('callLinks'))),
        createCallLink: (type) => data(axios.post(url('callLinksStore'), { type })),
        deleteCallLink: (token) => data(axios.delete(url('callLinkDestroy', token))),
        callLog: (before = null) => data(axios.get(url('callLog'), { params: before ? { before } : {} })),
        markCallsSeen: () => data(axios.post(url('callLogSeen'))),
        removeCall: (id) => data(axios.delete(url('callLogDestroy', id))),
        clearCalls: () => data(axios.delete(url('callLogClear'))),
        starred: (before = null) => data(axios.get(url('starred'), { params: before ? { before } : {} })),
        openViewOnce: (id) => data(axios.post(url('viewOnce', id))),
        setChatLockPin: (payload) => data(axios.post(url('chatLockPin'), payload)),
        removeChatLockPin: (password) => data(axios.delete(url('chatLockPinDestroy'), { data: { password } })),
        unlockChats: (pin) => data(axios.post(url('chatLockUnlock'), { pin })),
        lockChats: () => data(axios.post(url('chatLockLock'))),
        chatLists: () => data(axios.get(url('chatLists'))),
        createChatList: (payload) => data(axios.post(url('chatListsStore'), payload)),
        updateChatList: (id, payload) => data(axios.patch(url('chatListUpdate', id), payload)),
        deleteChatList: (id) => data(axios.delete(url('chatListDestroy', id))),
        updateChatSettings: (conversationId, changes) => data(axios.patch(url('conversationSettings', conversationId), changes)),
        clearChat: (conversationId, keepStarred = false) => data(axios.post(url('conversationClear', conversationId), { keep_starred: keepStarred })),
        deleteChat: (conversationId) => data(axios.delete(url('conversationDestroy', conversationId))),
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
