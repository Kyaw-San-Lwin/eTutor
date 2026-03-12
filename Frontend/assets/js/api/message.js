async function loadMessages(withUserId = null) {
  const params = {};
  if (withUserId) {
    params.with_user_id = withUserId;
  }

  const res = await apiRequest("message", "", "GET", null, {
    headers: {},
    query: params
  });
  if (!res || !res.success) {
    return [];
  }
  return res.data || [];
}

async function sendMessage(receiverId, message) {
  return apiRequest("message", "", "POST", { receiver_id: receiverId, message });
}
