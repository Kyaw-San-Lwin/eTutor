async function loadBlogs() {
  return apiRequest("blog", "", "GET", null);
}

async function createBlog(title, content) {
  return apiRequest("blog", "", "POST", { title, content });
}

async function listBlogComments(postId) {
  return apiRequest("blog_comment", "", "GET", null, {
    headers: {},
    query: { post_id: postId }
  });
}

async function createBlogComment(postId, comment) {
  return apiRequest("blog_comment", "", "POST", { post_id: postId, comment });
}
